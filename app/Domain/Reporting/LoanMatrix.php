<?php

namespace App\Domain\Reporting;

use App\Enums\LoanTransactionType;
use App\Models\Cycle;
use App\Models\CycleMonth;
use App\Models\LoanTransaction;
use App\Models\Member;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * The LOANS sheet of the group's workbook: members down the side, months across, and
 * what each member borrowed, repaid and still owed in each of them.
 *
 * Like the savings matrix it reads the ledger rather than the cached snapshots, so the
 * screen is right even when a rebuild is overdue. One grouped query covers the grid.
 */
class LoanMatrix
{
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

        $movements = $this->movementsByMemberMonth($months);

        $rows = $cycle->members()->get()
            ->map(fn (Member $member): array => $this->row($member, $months, $movements))
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
     * One member's line: a cell per month plus where they finished.
     *
     * @param  EloquentCollection<int, CycleMonth>  $months
     * @param  array<int, array<int, array<string, int>>>  $movements
     * @return array<string, mixed>
     */
    protected function row(Member $member, EloquentCollection $months, array $movements): array
    {
        $cells = [];
        $balance = 0;
        $borrowed = 0;
        $interestPaid = 0;
        $penalties = 0;

        foreach ($months as $month) {
            $cell = $movements[$member->id][$month->id] ?? [];

            $monthBorrowed = $cell['borrowed'] ?? 0;
            $monthRepaid = $cell['repaid'] ?? 0;
            $monthCharged = ($cell['interest_charged'] ?? 0) + ($cell['penalties'] ?? 0);

            $balance = max(0, $balance + $monthBorrowed + $monthCharged - $monthRepaid);
            $borrowed += $monthBorrowed;
            $interestPaid += $cell['interest_paid'] ?? 0;
            $penalties += $cell['penalties'] ?? 0;

            $cells[$month->id] = [
                'borrowed' => $monthBorrowed,
                'repaid' => $monthRepaid,
                'balance' => $balance,
            ];
        }

        return [
            'member_id' => $member->id,
            'member_number' => $member->member_number,
            'full_name' => $member->full_name,
            'status' => $member->status,
            'status_label' => $member->status->label(),
            'cells' => $cells,
            'borrowed_ngwee' => $borrowed,
            'interest_paid_ngwee' => $interestPaid,
            'penalties_ngwee' => $penalties,
            'balance_ngwee' => $balance,
        ];
    }

    /**
     * Every ledger movement in the cycle, grouped member by member and month by month.
     *
     * @param  EloquentCollection<int, CycleMonth>  $months
     * @return array<int, array<int, array<string, int>>>
     */
    protected function movementsByMemberMonth(EloquentCollection $months): array
    {
        $rows = LoanTransaction::query()
            ->join('loans', 'loans.id', '=', 'loan_transactions.loan_id')
            ->whereIn('loan_transactions.cycle_month_id', $months->pluck('id'))
            ->groupBy('loans.member_id', 'loan_transactions.cycle_month_id')
            ->selectRaw('loans.member_id, loan_transactions.cycle_month_id')
            ->selectRaw('SUM(CASE WHEN loan_transactions.type = ? THEN loan_transactions.amount_ngwee ELSE 0 END) AS borrowed', [LoanTransactionType::Disbursement->value])
            ->selectRaw('SUM(CASE WHEN loan_transactions.type = ? THEN loan_transactions.amount_ngwee ELSE 0 END) AS repaid', [LoanTransactionType::Repayment->value])
            ->selectRaw('SUM(CASE WHEN loan_transactions.type = ? THEN loan_transactions.amount_ngwee ELSE 0 END) AS interest_charged', [LoanTransactionType::InterestCharge->value])
            ->selectRaw('SUM(loan_transactions.interest_portion_ngwee) AS interest_paid')
            ->selectRaw('SUM(CASE WHEN loan_transactions.type IN (?, ?) THEN loan_transactions.amount_ngwee ELSE 0 END) AS penalties', [
                LoanTransactionType::LatePenaltyDaily->value,
                LoanTransactionType::MissedInstallmentPenalty->value,
            ])
            ->get();

        $grouped = [];

        foreach ($rows as $row) {
            $grouped[(int) $row->member_id][(int) $row->cycle_month_id] = [
                'borrowed' => (int) $row->borrowed,
                'repaid' => (int) $row->repaid,
                'interest_charged' => (int) $row->interest_charged,
                'interest_paid' => (int) $row->interest_paid,
                'penalties' => (int) $row->penalties,
            ];
        }

        return $grouped;
    }

    /**
     * @param  EloquentCollection<int, CycleMonth>  $months
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    protected function totals(EloquentCollection $months, array $rows): array
    {
        $monthTotals = [];

        foreach ($months as $month) {
            $monthTotals[$month->id] = [
                'borrowed' => array_sum(array_map(fn (array $row): int => $row['cells'][$month->id]['borrowed'], $rows)),
                'repaid' => array_sum(array_map(fn (array $row): int => $row['cells'][$month->id]['repaid'], $rows)),
                'balance' => array_sum(array_map(fn (array $row): int => $row['cells'][$month->id]['balance'], $rows)),
            ];
        }

        return [
            'months' => $monthTotals,
            'borrowed_ngwee' => array_sum(array_column($rows, 'borrowed_ngwee')),
            'interest_paid_ngwee' => array_sum(array_column($rows, 'interest_paid_ngwee')),
            'penalties_ngwee' => array_sum(array_column($rows, 'penalties_ngwee')),
            'balance_ngwee' => array_sum(array_column($rows, 'balance_ngwee')),
        ];
    }
}
