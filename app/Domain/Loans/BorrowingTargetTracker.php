<?php

namespace App\Domain\Loans;

use App\Enums\LoanTransactionType;
use App\Models\Cycle;
use App\Models\LoanTransaction;
use App\Models\Member;
use Illuminate\Support\Collection;

/**
 * Progress against the K50,000 each member is meant to borrow over the cycle.
 *
 * The target exists because the group's income is the interest its members pay: a
 * cycle where nobody borrows earns nobody anything. It is a goal the committee tracks
 * and talks about, never a rule — falling short of it blocks nothing.
 */
class BorrowingTargetTracker
{
    /**
     * Every member's borrowing against the target, largest shortfall last.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function for(Cycle $cycle): Collection
    {
        $borrowed = $this->borrowedByMember($cycle);
        $target = $cycle->borrowing_target_ngwee->getMinorAmount()->toInt();

        return $cycle->members()->get()->map(function (Member $member) use ($borrowed, $target): array {
            $total = $borrowed[$member->id] ?? 0;

            return [
                'member_id' => $member->id,
                'member_number' => $member->member_number,
                'full_name' => $member->full_name,
                'status' => $member->status,
                'status_label' => $member->status->label(),
                'borrowed_ngwee' => $total,
                'target_ngwee' => $target,
                'balance_to_borrow_ngwee' => max(0, $target - $total),
                'progress_percent' => $target === 0 ? 100 : (int) round($total / $target * 100),
                'under_target' => $total < $target,
            ];
        })->values();
    }

    /**
     * @return array<int, int>
     */
    protected function borrowedByMember(Cycle $cycle): array
    {
        return LoanTransaction::query()
            ->join('loans', 'loans.id', '=', 'loan_transactions.loan_id')
            ->where('loans.cycle_id', $cycle->id)
            ->where('loan_transactions.type', LoanTransactionType::Disbursement->value)
            ->groupBy('loans.member_id')
            ->selectRaw('loans.member_id, SUM(loan_transactions.amount_ngwee) AS borrowed')
            ->pluck('borrowed', 'member_id')
            ->map(fn ($value): int => (int) $value)
            ->all();
    }
}
