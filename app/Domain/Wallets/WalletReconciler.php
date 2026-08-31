<?php

namespace App\Domain\Wallets;

use App\Domain\Payments\PaymentGateway;
use App\Domain\SocialFund\SocialFundLedger;
use App\Enums\LoanStatus;
use App\Enums\PaymentPurpose;
use App\Enums\TransactionSource;
use App\Enums\WalletEntryType;
use App\Exceptions\PaymentGatewayException;
use App\Models\Cycle;
use App\Models\Loan;
use App\Models\Payout;
use App\Models\SavingsTransaction;
use App\Models\Wallet;
use App\Models\WalletEntry;
use Illuminate\Support\Facades\DB;

/**
 * The daily check that the wallet float is real money.
 *
 * Wallets introduce an internal balance that must always be backed by cash the group
 * actually holds. This is the only thing standing between the group and a float that
 * quietly does not exist, and it is also the first check in the system that catches a
 * fraud requiring no ledger tampering at all: a wallet credited with no money behind it
 * shows up here the next morning, and nowhere else.
 *
 * It must be run daily and alarmed on, not computed on request and forgotten.
 */
class WalletReconciler
{
    public function __construct(
        protected WalletLedger $ledger,
        protected WalletRegistry $wallets,
        protected PaymentGateway $gateway,
        protected SocialFundLedger $fund,
    ) {}

    /**
     * Invariant 1, and the weaker second check beside it.
     *
     * @param  int|null  $countedCashNgwee  what the treasurer actually counted in the
     *                                      tin; defaults to what the entries say should
     *                                      be there, which checks the books against the
     *                                      provider but not against the tin
     * @return array<string, mixed>
     */
    public function check(Cycle $cycle, ?int $countedCashNgwee = null): array
    {
        $walletTotal = $this->walletTotalNgwee($cycle);
        $cash = $countedCashNgwee ?? $this->cashTinNgwee($cycle);
        $inFlight = $this->withdrawalsInFlightNgwee($cycle);
        $provider = $this->providerBalanceNgwee();

        /*
         * sum(wallet balances) == provider balance + cash tin − withdrawals in flight.
         *
         * A withdrawal is debited from the wallet on initiation but has not yet left
         * the provider's account, so it sits on the provider's side of this sum until
         * the transfer confirms.
         */
        $expected = $provider === null ? null : $provider + $cash - $inFlight;
        $variance = $expected === null ? null : $walletTotal - $expected;

        $group = $this->wallets->group($cycle);
        $groupBalance = $this->ledger->balanceNgwee($group);
        $groupExpected = $this->expectedGroupBalanceNgwee($cycle);

        return [
            'wallet_total_ngwee' => $walletTotal,
            'member_liability_ngwee' => $this->memberLiabilityNgwee($cycle),
            'group_wallet_ngwee' => $groupBalance,
            'cash_tin_ngwee' => $cash,
            'cash_tin_counted' => $countedCashNgwee !== null,
            'withdrawals_in_flight_ngwee' => $inFlight,
            'provider_balance_ngwee' => $provider,
            'expected_wallet_total_ngwee' => $expected,
            'wallet_variance_ngwee' => $variance,
            'group_wallet_expected_ngwee' => $groupExpected,
            'group_wallet_variance_ngwee' => $groupBalance - $groupExpected,
            'balances' => $variance !== null && abs($variance) <= $this->tolerance(),
            'provider_unreachable' => $provider === null,
        ];
    }

    /** Every wallet entry in the cycle, summed. Nothing caches this. */
    public function walletTotalNgwee(Cycle $cycle): int
    {
        return (int) WalletEntry::query()->forCycle($cycle)->sum('amount_ngwee');
    }

    /**
     * What the group owes its members on demand.
     *
     * A liability, not group funds. It never appears in the social fund balance or the
     * savings pool, and it is the number that makes a wallet float a real obligation
     * rather than a bookkeeping convenience.
     */
    public function memberLiabilityNgwee(Cycle $cycle): int
    {
        return (int) WalletEntry::query()
            ->forCycle($cycle)
            ->whereIn('wallet_id', Wallet::query()->forCycle($cycle)->memberOwned()->select('id'))
            ->sum('amount_ngwee');
    }

    /**
     * What should be in the tin: cash in, less cash out.
     *
     * The one part of the float with no provider record behind it, which is exactly why
     * `TransactionSource::Cash` is named rather than left to look like a gateway
     * payment.
     */
    public function cashTinNgwee(Cycle $cycle): int
    {
        return (int) WalletEntry::query()
            ->forCycle($cycle)
            ->where('source', TransactionSource::Cash->value)
            ->sum('amount_ngwee');
    }

    /**
     * Money debited from a wallet whose transfer has not yet left the provider.
     *
     * Debit-on-initiation is what puts this line in the sum. It is the price of a
     * member never being able to spend the same balance twice.
     */
    public function withdrawalsInFlightNgwee(Cycle $cycle): int
    {
        return abs((int) WalletEntry::query()
            ->forCycle($cycle)
            ->whereIn('type', [WalletEntryType::Withdrawal->value, WalletEntryType::Fee->value])
            ->whereIn('payment_intent_id', DB::table('payment_intents')
                ->where('purpose', PaymentPurpose::WalletWithdrawal->value)
                ->whereIn('status', ['pending', 'awaiting_authorization', 'successful'])
                ->select('id'))
            ->sum('amount_ngwee'));
    }

    /** What the provider says the group's account holds, or null if it cannot be asked. */
    public function providerBalanceNgwee(): ?int
    {
        try {
            return $this->gateway->balanceNgwee();
        } catch (PaymentGatewayException) {
            /* Not news about the money. The run records that it could not ask. */
            return null;
        }
    }

    /**
     * The weaker second check: what the ledgers say the group should be holding.
     *
     * Savings the members have put in, plus the social fund, less the loan principal
     * that is out with borrowers and the payouts already handed over. Reported rather
     * than alarmed on: the group wallet opens at a recorded float rather than being
     * derived from the ledgers' whole history, so this drifts by construction until a
     * cycle has run start to finish on wallets.
     */
    public function expectedGroupBalanceNgwee(Cycle $cycle): int
    {
        $savings = (int) SavingsTransaction::query()
            ->whereIn('cycle_month_id', $cycle->months()->select('id'))
            ->sum('amount_ngwee');

        $fund = (int) $this->fund->entries($cycle)->sum('amount_ngwee');

        $lentOut = (int) Loan::query()
            ->forCycle($cycle)
            ->whereIn('status', [LoanStatus::Disbursed->value, LoanStatus::Repaying->value, LoanStatus::Defaulted->value])
            ->sum('current_balance_ngwee');

        $paidOut = (int) Payout::query()
            ->forCycle($cycle)
            ->whereNotNull('paid_at')
            ->sum('amount_ngwee');

        return $savings + $fund - $lentOut - $paidOut;
    }

    protected function tolerance(): int
    {
        return (int) config('wallets.reconciliation.tolerance_ngwee', 0);
    }
}
