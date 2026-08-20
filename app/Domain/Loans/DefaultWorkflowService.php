<?php

namespace App\Domain\Loans;

use App\Domain\Approvals\TwoPersonRule;
use App\Domain\Support\MoneyMutator;
use App\Enums\CollateralClaimStatus;
use App\Enums\LoanStatus;
use App\Exceptions\DomainRuleException;
use App\Models\CollateralClaim;
use App\Models\Loan;
use App\Models\Member;
use App\Support\Kwacha;
use Illuminate\Support\Carbon;

/**
 * What happens when a member stops paying.
 *
 * The constitution's guarantee clause lets the group recover an unpaid loan against the
 * borrower's household goods, itemised to the value outstanding. That is a serious step,
 * so it is a guided sequence rather than a button: the loan is declared in default, a
 * claim is drafted item by item, a second committee member signs it, and only then may
 * it be enforced.
 */
class DefaultWorkflowService
{
    public function __construct(
        protected LoanLedger $ledger,
        protected TwoPersonRule $twoPersonRule,
        protected MoneyMutator $mutator,
    ) {}

    public function markDefaulted(Loan $loan, Member $actor, string $reason): Loan
    {
        if (! in_array($loan->status, [LoanStatus::Disbursed, LoanStatus::Repaying], true)) {
            throw DomainRuleException::make(
                'Only a loan being repaid can be declared in default; this one is '
                    .strtolower($loan->status->label()).'.'
            );
        }

        return $this->mutator->mutate(
            $actor,
            "Declared loan #{$loan->id} for {$loan->member->full_name} in default: {$reason}",
            function () use ($loan): Loan {
                $loan->forceFill([
                    'status' => LoanStatus::Defaulted,
                    'defaulted_at' => Carbon::now(),
                ])->save();

                return $loan;
            },
            ['loan_id' => $loan->id],
        );
    }

    /**
     * Drafts the claim against the member's pledged goods.
     *
     * @param  array<int, array{description: string, estimated_value_ngwee: int}>  $items
     */
    public function openClaim(Loan $loan, array $items, Member $preparedBy): CollateralClaim
    {
        if ($loan->status !== LoanStatus::Defaulted) {
            throw DomainRuleException::make(
                'A collateral claim can only be raised against a loan in default.'
            );
        }

        if ($items === []) {
            throw DomainRuleException::make(
                'A claim must itemise the goods being claimed — the guarantee is against named items, not a sum.'
            );
        }

        $outstanding = $this->ledger->balanceNgwee($loan);
        $claimed = array_sum(array_column($items, 'estimated_value_ngwee'));

        if ($claimed < $outstanding) {
            throw DomainRuleException::make(
                'The items listed come to '.Kwacha::format($claimed).', short of the '
                    .Kwacha::format($outstanding).' still owed. List goods to at least the outstanding value.'
            );
        }

        return $this->mutator->mutate(
            $preparedBy,
            'Drafted a collateral claim of '.Kwacha::format($claimed)." against loan #{$loan->id}",
            fn (): CollateralClaim => CollateralClaim::create([
                'loan_id' => $loan->id,
                'status' => CollateralClaimStatus::Draft,
                'prepared_by_member_id' => $preparedBy->id,
                'items' => array_values($items),
                'claimed_value_ngwee' => $claimed,
                'outstanding_at_claim_ngwee' => $outstanding,
            ]),
            ['loan_id' => $loan->id],
        );
    }

    /** The second committee signature the guarantee clause requires. */
    public function signOff(CollateralClaim $claim, Member $secondSigner): CollateralClaim
    {
        if ($claim->status !== CollateralClaimStatus::Draft) {
            throw DomainRuleException::make(
                'This claim has already moved past sign-off.'
            );
        }

        $preparer = $claim->preparedBy;

        if ($preparer === null) {
            throw DomainRuleException::make('This claim has no preparer on record to confirm against.');
        }

        $this->twoPersonRule->assertDistinctCommittee($preparer, $secondSigner, $claim->loan->member);

        $claim->forceFill([
            'status' => CollateralClaimStatus::CommitteeSignOff,
            'second_signer_member_id' => $secondSigner->id,
            'signed_off_at' => Carbon::now(),
        ])->save();

        return $claim;
    }

    public function enforce(CollateralClaim $claim, Member $actor): CollateralClaim
    {
        if ($claim->status !== CollateralClaimStatus::CommitteeSignOff) {
            throw DomainRuleException::make(
                'A claim must carry two committee signatures before it can be enforced.'
            );
        }

        return $this->mutator->mutate(
            $actor,
            "Enforced the collateral claim on loan #{$claim->loan_id}",
            function () use ($claim): CollateralClaim {
                $claim->forceFill([
                    'status' => CollateralClaimStatus::Enforced,
                    'enforced_at' => Carbon::now(),
                ])->save();

                return $claim;
            },
            ['loan_id' => $claim->loan_id],
        );
    }

    /** The member settled after all, so the goods go back. */
    public function release(CollateralClaim $claim, Member $actor, ?string $note = null): CollateralClaim
    {
        if ($claim->status === CollateralClaimStatus::Released) {
            throw DomainRuleException::make('This claim has already been released.');
        }

        $claim->forceFill([
            'status' => CollateralClaimStatus::Released,
            'released_at' => Carbon::now(),
            'note' => $note,
        ])->save();

        return $claim;
    }
}
