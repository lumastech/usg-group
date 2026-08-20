<?php

namespace App\Domain\Reporting;

use App\Domain\Loans\OutstandingLoanProvider;
use App\Enums\SavingsTransactionType;
use App\Models\Cycle;
use App\Models\CycleMonth;
use App\Models\InterestAllocation;
use App\Models\Member;
use App\Models\SavingsTransaction;
use App\Support\Kwacha;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * The SAVINGS sheet of the group's workbook: members down the side, a Savings and an
 * Interest figure for each month across the top, and each member's totals and net
 * value at the end.
 *
 * It reads the ledgers directly rather than the cached snapshots, so the screen is
 * right even when a rebuild is overdue. Two grouped queries cover the whole grid,
 * which for a thirty-member cycle is cheaper than loading the cache would be.
 */
class SavingsMatrix
{
    public function __construct(protected OutstandingLoanProvider $loans) {}

    /**
     * @return array{
     *     months: array<int, array<string, mixed>>,
     *     rows: array<int, array<string, mixed>>,
     *     totals: array<string, mixed>
     * }
     */
    public function for(Cycle $cycle, ?int $throughSequence = null): array
    {
        $months = $cycle->months()
            ->when($throughSequence !== null, fn ($query) => $query->where('sequence', '<=', $throughSequence))
            ->get();

        $members = $cycle->members()->get();
        $savings = $this->savingsByMemberMonth($months);
        $interest = $this->interestByMemberMonth($months);

        $rows = $members
            ->map(fn (Member $member): array => $this->row($member, $months, $savings, $interest))
            ->values()
            ->all();

        return [
            'months' => $months->map(fn (CycleMonth $month): array => [
                'id' => $month->id,
                'sequence' => $month->sequence,
                'label' => $month->month->format('M'),
                'year' => $month->month->format('Y'),
                'full_label' => $month->label(),
                'lockdown' => $cycle->isLockdownMonth($month->sequence),
            ])->all(),
            'rows' => $rows,
            'totals' => $this->totals($months, $rows),
        ];
    }

    /**
     * One member's own view of the same grid: a line per month, the running totals
     * behind it, and where they stand at the end.
     *
     * @return array{months: array<int, array<string, mixed>>, totals: array<string, mixed>}
     */
    public function forMember(Cycle $cycle, Member $member): array
    {
        $matrix = $this->for($cycle);
        $row = collect($matrix['rows'])->firstWhere('member_id', $member->id);

        $cumulativeSavings = 0;
        $cumulativeInterest = 0;

        $months = collect($matrix['months'])
            ->map(function (array $month) use ($row, &$cumulativeSavings, &$cumulativeInterest): array {
                $cell = $row['cells'][$month['id']] ?? ['savings' => 0, 'interest' => 0];

                $cumulativeSavings += $cell['savings'];
                $cumulativeInterest += $cell['interest'];

                return [
                    'month_id' => $month['id'],
                    'sequence' => $month['sequence'],
                    'label' => $month['label'],
                    'full_label' => $month['full_label'],
                    'lockdown' => $month['lockdown'],
                    'savings_ngwee' => $cell['savings'],
                    'interest_ngwee' => $cell['interest'],
                    'cumulative_savings_ngwee' => $cumulativeSavings,
                    'cumulative_interest_ngwee' => $cumulativeInterest,
                ];
            })
            ->all();

        return [
            'months' => $months,
            'totals' => [
                'savings_ngwee' => $row['total_savings_ngwee'] ?? 0,
                'interest_ngwee' => $row['total_interest_ngwee'] ?? 0,
                'loan_balance_ngwee' => $row['loan_balance_ngwee'] ?? 0,
                'net_value_ngwee' => $row['net_value_ngwee'] ?? 0,
            ],
        ];
    }

    /**
     * One member's line across the cycle.
     *
     * @param  EloquentCollection<int, CycleMonth>  $months
     * @param  array<int, array<int, int>>  $savings
     * @param  array<int, array<int, int>>  $interest
     * @return array<string, mixed>
     */
    protected function row(Member $member, EloquentCollection $months, array $savings, array $interest): array
    {
        $cells = [];
        $totalSavings = 0;
        $totalInterest = 0;

        foreach ($months as $month) {
            $monthSavings = $savings[$member->id][$month->id] ?? 0;
            $monthInterest = $interest[$member->id][$month->id] ?? 0;

            $totalSavings += $monthSavings;
            $totalInterest += $monthInterest;

            $cells[$month->id] = ['savings' => $monthSavings, 'interest' => $monthInterest];
        }

        $lastMonth = $months->last();

        $loanBalance = $lastMonth === null
            ? 0
            : Kwacha::toNgwee($this->loans->balanceFor($member, $lastMonth))
                + Kwacha::toNgwee($this->loans->socialFundBalanceFor($member, $lastMonth));

        return [
            'member_id' => $member->id,
            'member_number' => $member->member_number,
            'full_name' => $member->full_name,
            'status' => $member->status,
            'status_label' => $member->status->label(),
            'is_diaspora' => $member->is_diaspora,
            'cells' => $cells,
            'total_savings_ngwee' => $totalSavings,
            'total_interest_ngwee' => $totalInterest,
            'loan_balance_ngwee' => $loanBalance,
            'net_value_ngwee' => $totalSavings + $totalInterest - $loanBalance,
        ];
    }

    /**
     * Column totals plus the group's bottom line.
     *
     * @param  EloquentCollection<int, CycleMonth>  $months
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    protected function totals(EloquentCollection $months, array $rows): array
    {
        $perMonth = [];

        foreach ($months as $month) {
            $perMonth[$month->id] = [
                'savings' => array_sum(array_map(
                    fn (array $row): int => $row['cells'][$month->id]['savings'] ?? 0,
                    $rows,
                )),
                'interest' => array_sum(array_map(
                    fn (array $row): int => $row['cells'][$month->id]['interest'] ?? 0,
                    $rows,
                )),
            ];
        }

        return [
            'months' => $perMonth,
            'total_savings_ngwee' => array_sum(array_column($rows, 'total_savings_ngwee')),
            'total_interest_ngwee' => array_sum(array_column($rows, 'total_interest_ngwee')),
            'loan_balance_ngwee' => array_sum(array_column($rows, 'loan_balance_ngwee')),
            'net_value_ngwee' => array_sum(array_column($rows, 'net_value_ngwee')),
        ];
    }

    /**
     * @param  EloquentCollection<int, CycleMonth>  $months
     * @return array<int, array<int, int>> member id => month id => ngwee
     */
    protected function savingsByMemberMonth(EloquentCollection $months): array
    {
        return SavingsTransaction::query()
            ->whereIn('cycle_month_id', $months->modelKeys())
            ->whereIn('type', [
                SavingsTransactionType::Contribution->value,
                SavingsTransactionType::Adjustment->value,
                SavingsTransactionType::ImportOpening->value,
            ])
            ->selectRaw('member_id, cycle_month_id, SUM(amount_ngwee) as total')
            ->groupBy('member_id', 'cycle_month_id')
            ->get()
            ->groupBy('member_id')
            ->map(fn (Collection $rows): array => $rows->pluck('total', 'cycle_month_id')
                ->map(fn ($total): int => (int) $total)
                ->all())
            ->all();
    }

    /**
     * @param  EloquentCollection<int, CycleMonth>  $months
     * @return array<int, array<int, int>> member id => month id => ngwee
     */
    protected function interestByMemberMonth(EloquentCollection $months): array
    {
        return InterestAllocation::query()
            ->whereIn('cycle_month_id', $months->modelKeys())
            ->selectRaw('member_id, cycle_month_id, SUM(amount_ngwee) as total')
            ->groupBy('member_id', 'cycle_month_id')
            ->get()
            ->groupBy('member_id')
            ->map(fn (Collection $rows): array => $rows->pluck('total', 'cycle_month_id')
                ->map(fn ($total): int => (int) $total)
                ->all())
            ->all();
    }
}
