<?php

namespace App\Domain\Loans;

use App\Enums\LoanStatus;
use App\Enums\LoanTransactionType;
use App\Models\CycleMonth;
use App\Models\Loan;
use App\Models\LoanScheduleItem;
use App\Models\LoanTransaction;
use App\Support\Kwacha;
use Illuminate\Support\Collection;

/**
 * Charges each month's interest on the loans that are running.
 *
 * Interest is 5% a month on the principal still outstanding, so it falls as the loan is
 * repaid: a K10,000 loan pays K500 in its first month and K125 in its fourth. The charge
 * is posted on the month's trading date, which is also the date the installment falls
 * due, and the schedule's *current* expected figures move with it. The original figures
 * are left alone — a member who paid late should be able to see the gap.
 */
class InterestEngine
{
    public function __construct(protected LoanLedger $ledger) {}

    /**
     * Posts the month's interest for every loan being repaid.
     *
     * Idempotent: a loan that already carries an interest charge for the month is
     * skipped, so re-running the trading-day job cannot double-charge anyone.
     *
     * @return Collection<int, LoanTransaction>
     */
    public function postForMonth(CycleMonth $month): Collection
    {
        return Loan::query()
            ->forCycle($month->cycle_id)
            ->whereIn('status', [LoanStatus::Disbursed->value, LoanStatus::Repaying->value])
            ->with('member')
            ->get()
            ->map(fn (Loan $loan): ?LoanTransaction => $this->postFor($loan, $month))
            ->filter()
            ->values();
    }

    /** The month's interest for one loan, or null when there is nothing to charge. */
    public function postFor(Loan $loan, CycleMonth $month): ?LoanTransaction
    {
        if ($this->alreadyCharged($loan, $month)) {
            return null;
        }

        $item = $loan->scheduleItems()->where('cycle_month_id', $month->id)->first();

        if ($item === null) {
            return null;
        }

        $interest = $this->interestFor($loan);

        if ($interest <= 0) {
            return null;
        }

        $transaction = $this->ledger->post(
            $loan,
            LoanTransactionType::InterestCharge,
            Kwacha::ofNgwee($interest),
            $month->disbursement_on,
            $month,
            notes: '5% of '.Kwacha::format($loan->principalOutstandingNgwee()).' outstanding principal',
        );

        $this->reprice($item, $interest);

        if ($loan->status === LoanStatus::Disbursed) {
            $loan->forceFill(['status' => LoanStatus::Repaying])->save();
        }

        return $transaction;
    }

    /** One month's interest on what the member still owes in principal. */
    public function interestFor(Loan $loan): int
    {
        return (int) round(
            $loan->principalOutstandingNgwee() * $loan->cycle->monthly_interest_bps / 10_000
        );
    }

    public function alreadyCharged(Loan $loan, CycleMonth $month): bool
    {
        return $loan->transactions()
            ->where('type', LoanTransactionType::InterestCharge->value)
            ->where('cycle_month_id', $month->id)
            ->exists();
    }

    /**
     * Moves an installment's current expectation to the interest actually charged.
     *
     * The original columns are never touched, so the detail screen can show the member
     * the schedule they were handed beside the one they are now on.
     */
    protected function reprice(LoanScheduleItem $item, int $interestNgwee): void
    {
        $item->forceFill([
            'interest_due_ngwee' => $interestNgwee,
            'amount_due_ngwee' => $item->getRawOriginal('principal_due_ngwee') + $interestNgwee,
        ])->save();
    }
}
