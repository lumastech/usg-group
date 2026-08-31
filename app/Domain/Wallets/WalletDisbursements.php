<?php

namespace App\Domain\Wallets;

use App\Domain\Loans\LoanDisbursementQueue;
use App\Domain\SocialFund\DiasporaApportionmentService;
use App\Domain\SocialFund\GrantClaimService;
use App\Enums\LoanTransactionType;
use App\Enums\WalletTransferPurpose;
use App\Exceptions\DomainRuleException;
use App\Models\CycleMonth;
use App\Models\DiasporaApportionmentItem;
use App\Models\FuneralGrantClaim;
use App\Models\Loan;
use App\Models\Member;
use App\Models\Payout;
use App\Models\UnityBabyClaim;
use App\Models\WalletTransfer;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * What the group pays a member, into their wallet.
 *
 * The gateway drops out of every one of these. A loan, a grant, a share of a diaspora
 * apportionment and a share-out payout are all now a database transaction that cannot
 * half-succeed, and the member decides for themselves when to take the money out.
 *
 * The two-signature rules are untouched. Moving money inside the books rather than
 * across a network does not change who has to agree to it — `WalletTransferService`
 * asks `TwoPersonRule` for exactly the purposes `PaymentPurpose` asked for it before.
 *
 * `PayoutExecutor` is not called from here and is not modified. It remains the single
 * irreversible act — two signatures, the freeze, the record. What changes is only that
 * the money it settles lands in a wallet instead of waiting for a transfer.
 */
class WalletDisbursements
{
    public function __construct(
        protected WalletTransferService $transfers,
        protected WalletRegistry $wallets,
        protected LoanDisbursementQueue $queue,
        protected GrantClaimService $grants,
        protected DiasporaApportionmentService $apportionments,
    ) {}

    /**
     * Sends an approved loan to the member's wallet.
     *
     * The queue re-checks eligibility and the disbursement order exactly as it always
     * has, inside the transfer: a member whose position changed between approval and
     * payment is caught here, and the refusal leaves no trace of a loan they never
     * received. There is no window in which the loan is marked disbursed and the money
     * has not arrived, because both happen or neither does.
     */
    public function disburseLoan(
        Loan $loan,
        CycleMonth $month,
        Member $actor,
        ?string $outOfOrderReason = null,
    ): WalletTransfer {
        return $this->transfers->transfer(
            from: $this->wallets->group($loan->cycle),
            to: $this->wallets->forMember($loan->member, $loan->cycle),
            amount: $loan->principal_ngwee,
            purpose: WalletTransferPurpose::LoanDisbursement,
            actor: $actor,
            payable: $loan,
            occurredAt: $month->disbursement_on ?? Carbon::now(),
            note: "Loan #{$loan->id}",
            record: function () use ($loan, $month, $actor, $outOfOrderReason): ?Model {
                $this->queue->disburse($loan, $month, $actor, $outOfOrderReason);

                return $loan->transactions()
                    ->where('type', LoanTransactionType::Disbursement->value)
                    ->latest('id')
                    ->first();
            },
        );
    }

    /**
     * Pays an approved grant into the member's wallet.
     *
     * The fund is debited by `GrantClaimService` with the two signatures captured at
     * approval, unchanged. A funeral grant paid to a next of kin rather than to the
     * member still lands in the member's wallet — where the money goes from there is a
     * withdrawal, and the destination controls apply to it there.
     *
     * @param  FuneralGrantClaim|UnityBabyClaim  $claim
     */
    public function payGrant(
        Model $claim,
        Member $actor,
        Member $secondApprover,
        ?CarbonInterface $paidOn = null,
    ): WalletTransfer {
        $purpose = $claim instanceof FuneralGrantClaim
            ? WalletTransferPurpose::FuneralGrant
            : WalletTransferPurpose::UnityBabyGrant;

        return $this->transfers->transfer(
            from: $this->wallets->group($claim->cycle),
            to: $this->wallets->forMember($claim->member, $claim->cycle),
            amount: $claim->amount_ngwee,
            purpose: $purpose,
            actor: $actor,
            payable: $claim,
            secondApprover: $secondApprover,
            occurredAt: $paidOn ?? Carbon::now(),
            note: $purpose->label().' for '.$claim->subject(),
            record: fn (): Model => $this->grants->pay($claim, $actor, $secondApprover, $paidOn),
        );
    }

    /** A diaspora member's share of an apportionment, into their wallet. */
    public function payApportionment(
        DiasporaApportionmentItem $item,
        Member $actor,
        ?CarbonInterface $paidOn = null,
        ?string $reference = null,
    ): WalletTransfer {
        $apportionment = $item->apportionment;
        $second = $apportionment->secondApprover ?? $apportionment->recordedBy;

        if ($second === null) {
            throw DomainRuleException::make(
                'This apportionment has no second signature recorded, so its shares cannot be paid.'
            );
        }

        return $this->transfers->transfer(
            from: $this->wallets->group($apportionment->cycle),
            to: $this->wallets->forMember($item->member, $apportionment->cycle),
            amount: $item->amount_ngwee,
            purpose: WalletTransferPurpose::DiasporaApportionment,
            actor: $apportionment->recordedBy ?? $actor,
            payable: $item,
            secondApprover: $second,
            occurredAt: $paidOn ?? Carbon::now(),
            note: 'Diaspora apportionment declared '.$apportionment->declared_on->format('j M Y'),
            record: fn (): Model => $this->apportionments->confirmTransfer($item, $actor, $paidOn, $reference),
        );
    }

    /**
     * Pays a settled closure into the member's wallet.
     *
     * The payout was executed, signed and frozen before any of this and stands whether
     * or not it has been paid. What is different from the provider path is that this
     * cannot fail halfway: the member's balance and `payouts.paid_at` move together.
     */
    public function payPayout(
        Payout $payout,
        Member $actor,
        Member $secondApprover,
        bool $isShareOut = false,
    ): WalletTransfer {
        if ($payout->isPaid()) {
            throw DomainRuleException::make('That payout has already been paid.');
        }

        return $this->transfers->transfer(
            from: $this->wallets->group($payout->cycle),
            to: $this->wallets->forMember($payout->member, $payout->cycle),
            amount: $payout->amount_ngwee,
            purpose: $isShareOut ? WalletTransferPurpose::ShareOut : WalletTransferPurpose::Payout,
            actor: $actor,
            payable: $payout,
            secondApprover: $secondApprover,
            note: 'Unity Savings '.($isShareOut ? 'share-out' : 'payout'),
            record: function () use ($payout): Model {
                $payout->forceFill([
                    'paid_at' => Carbon::now(),
                    'paid_method' => 'wallet',
                ])->save();

                return $payout->refresh();
            },
        );
    }
}
