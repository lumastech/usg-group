<?php

namespace App\Domain\Loans;

use App\Enums\LoanScheduleItemStatus;
use App\Models\Cycle;
use App\Models\CycleMonth;
use App\Models\Loan;
use App\Models\LoanScheduleItem;
use App\Support\Kwacha;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Builds a loan's repayment schedule.
 *
 * The principal is split into equal monthly installments; each month also carries that
 * month's interest, charged at 5% of the principal still outstanding. So a K10,000 loan
 * over four months repays K2,500 of principal every month while the interest falls from
 * K500 to K125 — the reducing balance the constitution describes.
 *
 * Repayments fall due on the month's disbursement date, the adjusted 7th, so they land
 * on the same trading day the fund meets. Nothing may fall after the cycle's final
 * repayment deadline, which is what forces a compressed tenor late in the year.
 */
class RepaymentScheduleGenerator
{
    /**
     * The months a loan disbursed in `$anchor` could still use to repay.
     *
     * Repayment starts the month after disbursement and the last usable month is the
     * one holding the final repayment deadline.
     */
    public function monthsAvailableFrom(Cycle $cycle, CycleMonth $anchor): int
    {
        return $this->repaymentMonthsFrom($cycle, $anchor)->count();
    }

    /**
     * Every month a loan disbursed in `$anchor` may repay in, in order.
     *
     * @return Collection<int, CycleMonth>
     */
    public function repaymentMonthsFrom(Cycle $cycle, CycleMonth $anchor): Collection
    {
        $deadline = $cycle->final_repayment_date;

        return $cycle->months()->get()
            ->filter(fn (CycleMonth $month): bool => $month->sequence > $anchor->sequence)
            ->filter(fn (CycleMonth $month): bool => $month->month->lessThanOrEqualTo($deadline->copy()->startOfMonth()))
            ->values();
    }

    /**
     * The installments a principal would produce, without writing anything.
     *
     * This is what the request wizard previews and what the schedule is written from,
     * so the member sees exactly the figures they will later be held to.
     *
     * @return array<int, array{
     *     sequence: int,
     *     cycle_month_id: int,
     *     due_month: string,
     *     due_on: string,
     *     month_label: string,
     *     opening_principal_ngwee: int,
     *     principal_ngwee: int,
     *     interest_ngwee: int,
     *     amount_due_ngwee: int
     * }>
     */
    public function preview(Cycle $cycle, CycleMonth $anchor, LoanTenor $tenor): array
    {
        $months = $this->repaymentMonthsFrom($cycle, $anchor)->take($tenor->months);
        $effective = $tenor->compressedTo($months->count());
        $installments = $effective->principalInstallmentsNgwee();

        $outstanding = $effective->principalNgwee;
        $schedule = [];

        foreach ($months->values() as $index => $month) {
            $principal = $installments[$index];
            $interest = $this->interestOn($outstanding, $cycle->monthly_interest_bps);

            $schedule[] = [
                'sequence' => $index + 1,
                'cycle_month_id' => $month->id,
                'due_month' => $month->month->toDateString(),
                'due_on' => $this->dueDateFor($cycle, $month)->toDateString(),
                'month_label' => $month->label(),
                'opening_principal_ngwee' => $outstanding,
                'principal_ngwee' => $principal,
                'interest_ngwee' => $interest,
                'amount_due_ngwee' => $principal + $interest,
            ];

            $outstanding -= $principal;
        }

        return $schedule;
    }

    /**
     * Writes the schedule a disbursed loan will be held to.
     *
     * Both the original and the current expected figures are seeded from the same
     * preview; the InterestEngine moves the current ones as the real balance reduces.
     *
     * @return Collection<int, LoanScheduleItem>
     */
    public function generate(Loan $loan, CycleMonth $disbursementMonth): Collection
    {
        $cycle = $loan->cycle;
        $tenor = LoanTenor::forNgwee(Kwacha::toNgwee($loan->principal_ngwee))
            ->compressedTo($loan->tenor_months);

        $loan->scheduleItems()->delete();

        return collect($this->preview($cycle, $disbursementMonth, $tenor))
            ->map(fn (array $row): LoanScheduleItem => LoanScheduleItem::create([
                'loan_id' => $loan->id,
                'cycle_month_id' => $row['cycle_month_id'],
                'sequence' => $row['sequence'],
                'due_month' => $row['due_month'],
                'due_on' => $row['due_on'],
                'original_principal_ngwee' => $row['principal_ngwee'],
                'original_interest_ngwee' => $row['interest_ngwee'],
                'original_amount_due_ngwee' => $row['amount_due_ngwee'],
                'principal_due_ngwee' => $row['principal_ngwee'],
                'interest_due_ngwee' => $row['interest_ngwee'],
                'amount_due_ngwee' => $row['amount_due_ngwee'],
                'amount_paid_ngwee' => 0,
                'status' => LoanScheduleItemStatus::Pending,
            ]));
    }

    /**
     * The trading date a month's installment is due on.
     *
     * Clamped to the cycle's final repayment deadline: in the closing month the
     * adjusted 7th can fall past it, and the deadline is the harder of the two.
     */
    public function dueDateFor(Cycle $cycle, CycleMonth $month): CarbonInterface
    {
        return $month->disbursement_on->greaterThan($cycle->final_repayment_date)
            ? $cycle->final_repayment_date
            : $month->disbursement_on;
    }

    /** One month's interest on an outstanding principal, in whole ngwee. */
    public function interestOn(int $principalNgwee, int $bps): int
    {
        return (int) round($principalNgwee * $bps / 10_000);
    }
}
