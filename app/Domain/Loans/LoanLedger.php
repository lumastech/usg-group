<?php

namespace App\Domain\Loans;

use App\Domain\Support\MoneyMutator;
use App\Enums\LoanTransactionType;
use App\Models\CycleMonth;
use App\Models\Loan;
use App\Models\LoanTransaction;
use App\Models\Member;
use App\Support\Kwacha;
use Brick\Money\Money;
use Carbon\CarbonInterface;

/**
 * The append-only record of everything that happened to a loan.
 *
 * Every charge and every repayment is posted here, and the loan's balance is nothing
 * more than the running sum of these entries. `current_balance_ngwee` on the loan is a
 * cache of that sum for listing screens — rebuild() recomputes it, and it must always
 * agree with the ledger.
 */
class LoanLedger
{
    public function __construct(protected MoneyMutator $mutator) {}

    /**
     * Posts one entry and moves the loan's cached balance with it.
     *
     * `$portions` splits a repayment across penalties, interest and principal. It is
     * how interest paid, the month's interest pool and the remaining principal are all
     * derived later without a second source of truth.
     *
     * @param  array{principal?: int, interest?: int, penalty?: int}  $portions
     */
    public function post(
        Loan $loan,
        LoanTransactionType $type,
        Money $amount,
        CarbonInterface $occurredOn,
        ?CycleMonth $month = null,
        ?Member $actor = null,
        array $portions = [],
        ?string $notes = null,
    ): LoanTransaction {
        $reason = $type->label().' of '.Kwacha::format($amount)
            ." on loan #{$loan->id} for {$loan->member->full_name}";

        $context = ['loan_id' => $loan->id, 'member_id' => $loan->member_id, 'type' => $type->value];

        $write = function () use ($loan, $type, $amount, $occurredOn, $month, $actor, $portions, $notes): LoanTransaction {
            $balanceAfter = $this->balanceNgwee($loan) + (Kwacha::toNgwee($amount) * $type->signedFactor());

            $transaction = LoanTransaction::create([
                'loan_id' => $loan->id,
                'cycle_month_id' => $month?->id,
                'recorded_by_member_id' => $actor?->id,
                'type' => $type,
                'amount_ngwee' => $amount,
                'occurred_on' => $occurredOn,
                'balance_after_ngwee' => $balanceAfter,
                'principal_portion_ngwee' => $portions['principal'] ?? 0,
                'interest_portion_ngwee' => $portions['interest'] ?? 0,
                'penalty_portion_ngwee' => $portions['penalty'] ?? 0,
                'notes' => $notes,
            ]);

            $loan->forceFill(['current_balance_ngwee' => $balanceAfter])->save();

            return $transaction;
        };

        return $actor === null
            ? $this->mutator->system($reason, $write, $context)
            : $this->mutator->mutate($actor, $reason, $write, $context);
    }

    /** The balance the ledger says is owed, computed from the entries themselves. */
    public function balanceNgwee(Loan $loan): int
    {
        return (int) $loan->transactions()
            ->selectRaw("COALESCE(SUM(CASE WHEN type IN ('repayment', 'write_off') THEN -amount_ngwee ELSE amount_ngwee END), 0) AS balance")
            ->value('balance');
    }

    /**
     * Re-derives the cached balance from the ledger and saves it.
     *
     * The entries are immutable, so this only ever moves the denormalised column —
     * which is exactly why the rebuild is safe to run at any time.
     */
    public function rebuild(Loan $loan): Loan
    {
        $loan->forceFill(['current_balance_ngwee' => $this->balanceNgwee($loan)])->save();

        return $loan;
    }

    /** What is still owed on penalties that have not been cleared by repayments. */
    public function outstandingPenaltiesNgwee(Loan $loan): int
    {
        $charged = (int) $loan->transactions()
            ->whereIn('type', [
                LoanTransactionType::LatePenaltyDaily->value,
                LoanTransactionType::MissedInstallmentPenalty->value,
            ])
            ->sum('amount_ngwee');

        return max(0, $charged - (int) $loan->transactions()->sum('penalty_portion_ngwee'));
    }

    /** Interest charged but not yet paid. */
    public function outstandingInterestNgwee(Loan $loan): int
    {
        $charged = (int) $loan->transactions()
            ->where('type', LoanTransactionType::InterestCharge->value)
            ->sum('amount_ngwee');

        return max(0, $charged - (int) $loan->transactions()->sum('interest_portion_ngwee'));
    }
}
