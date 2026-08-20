<?php

namespace App\Domain\Reporting;

use App\Enums\LoanStatus;
use App\Enums\LoanTransactionType;
use App\Enums\MemberStatus;
use App\Models\Cycle;
use App\Models\CycleMonth;
use App\Models\InterestAllocation;
use App\Models\Loan;
use App\Models\LoanTransaction;
use App\Models\Member;
use App\Models\SavingsTransaction;
use App\Support\Kwacha;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Group-wide figures for the administration dashboard.
 *
 * Social fund totals are still reported as null until module 4 lands, so the dashboard
 * can show "not yet tracked" rather than a misleading zero. Lending is real from module
 * 3 onwards and reads straight off the loan ledger.
 */
class CycleOverview
{
    /**
     * @return array<string, mixed>
     */
    public function for(Cycle $cycle, ?CarbonInterface $asOf = null): array
    {
        $asOf ??= Carbon::today();
        $currentMonth = $this->currentMonth($cycle, $asOf);

        return [
            'cycle' => $this->cycleSummary($cycle, $asOf),
            'month' => $this->monthSummary($cycle, $currentMonth, $asOf),
            'members' => $this->memberSummary($cycle),
            'money' => $this->moneySummary($cycle, $currentMonth),
            'lending' => $this->lendingSummary($cycle, $currentMonth),
        ];
    }

    /** The month containing the given date, falling back to the nearest cycle bound. */
    public function currentMonth(Cycle $cycle, CarbonInterface $asOf): ?CycleMonth
    {
        return $cycle->months()
            ->whereYear('month', $asOf->year)
            ->whereMonth('month', $asOf->month)
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    protected function cycleSummary(Cycle $cycle, CarbonInterface $asOf): array
    {
        $daysRemaining = $cycle->daysToFinalRepayment($asOf);

        return [
            'name' => $cycle->name,
            'status' => $cycle->status->value,
            'starts_on' => $cycle->starts_on->toDateString(),
            'ends_on' => $cycle->ends_on->toDateString(),
            'final_repayment_date' => $cycle->final_repayment_date->toDateString(),
            'days_to_final_repayment' => $daysRemaining,
            'deadline_passed' => $daysRemaining < 0,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function monthSummary(Cycle $cycle, ?CycleMonth $month, CarbonInterface $asOf): ?array
    {
        if ($month === null) {
            return null;
        }

        return [
            'label' => $month->label(),
            'sequence' => $month->sequence,
            'declarations_open' => $month->declarationsOpenAt($asOf),
            'declarations_open_at' => $month->declarations_open_at->toIso8601String(),
            'declarations_close_at' => $month->declarations_close_at->toIso8601String(),
            'trading_starts_on' => $month->trading_starts_on->toDateString(),
            'disbursement_on' => $month->disbursement_on->toDateString(),
            'lockdown_active' => $cycle->isLockdownMonth($month->sequence),
            'registration_open' => $cycle->registrationOpenForMonth($month->sequence),
            'savings_cap' => ($cap = $cycle->savingsCapForMonth($month->sequence)) !== null
                ? Kwacha::format($cap)
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function memberSummary(Cycle $cycle): array
    {
        $byStatus = $cycle->members()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'total' => (int) $byStatus->sum(),
            'active' => (int) $byStatus->get(MemberStatus::Active->value, 0),
            'left_early' => (int) $byStatus->get(MemberStatus::LeftEarly->value, 0),
            'expelled' => (int) $byStatus->get(MemberStatus::Expelled->value, 0),
            'deceased' => (int) $byStatus->get(MemberStatus::Deceased->value, 0),
            'diaspora' => $cycle->members()->where('is_diaspora', true)->count(),
            'joining_fees_outstanding' => $cycle->members()->where('joining_fee_paid', false)->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function moneySummary(Cycle $cycle, ?CycleMonth $month): array
    {
        $memberIds = $cycle->members()->pluck('id');
        $monthIds = $cycle->months()->pluck('id');

        $totalSavings = (int) SavingsTransaction::whereIn('member_id', $memberIds)->sum('amount_ngwee');
        $totalInterest = (int) InterestAllocation::whereIn('member_id', $memberIds)->sum('amount_ngwee');

        $monthSavings = $month === null ? 0 : (int) SavingsTransaction::query()
            ->whereIn('member_id', $memberIds)
            ->where('cycle_month_id', $month->id)
            ->sum('amount_ngwee');

        $savedThisMonth = $month === null ? 0 : SavingsTransaction::query()
            ->whereIn('member_id', $memberIds)
            ->where('cycle_month_id', $month->id)
            ->distinct('member_id')
            ->count('member_id');

        return [
            'total_savings' => Kwacha::format($totalSavings),
            'total_interest' => Kwacha::format($totalInterest),
            'group_wealth' => Kwacha::format($totalSavings + $totalInterest),
            'month_savings' => Kwacha::format($monthSavings),
            'members_saved_this_month' => $savedThisMonth,
            'ledger_started' => $totalSavings > 0 || $monthIds->isEmpty(),

            'loans_outstanding' => Kwacha::format($this->outstandingLoansNgwee($cycle)),

            // Awaiting the social fund module.
            'social_fund_balance' => null,
            'negative_net_value_members' => null,
        ];
    }

    /**
     * What the fund has lent out, what it is about to lend, and who is behind.
     *
     * Every figure is read off the loan ledger rather than the cached snapshots, so the
     * dashboard is right on the trading day even when a rebuild is overdue.
     *
     * @return array<string, mixed>
     */
    protected function lendingSummary(Cycle $cycle, ?CycleMonth $month): array
    {
        $queue = Loan::query()
            ->forCycle($cycle)
            ->where('status', LoanStatus::Approved->value)
            ->get(['id', 'principal_ngwee']);

        return [
            'outstanding_ngwee' => $this->outstandingLoansNgwee($cycle),
            'loans_running' => Loan::query()->forCycle($cycle)->outstanding()->count(),
            'queue_count' => $queue->count(),
            'queue_ngwee' => (int) $queue->sum(fn (Loan $loan): int => Kwacha::toNgwee($loan->principal_ngwee)),
            'members_penalised_this_month' => $this->membersPenalisedIn($cycle, $month),
        ];
    }

    /** The group's money still out on loan, from the ledger's own running sum. */
    protected function outstandingLoansNgwee(Cycle $cycle): int
    {
        return max(0, (int) LoanTransaction::query()
            ->join('loans', 'loans.id', '=', 'loan_transactions.loan_id')
            ->where('loans.cycle_id', $cycle->id)
            ->selectRaw("COALESCE(SUM(CASE WHEN loan_transactions.type IN ('repayment', 'write_off') THEN -loan_transactions.amount_ngwee ELSE loan_transactions.amount_ngwee END), 0) AS balance")
            ->value('balance'));
    }

    /** How many members were charged a penalty in the month, late or missed alike. */
    protected function membersPenalisedIn(Cycle $cycle, ?CycleMonth $month): int
    {
        if ($month === null) {
            return 0;
        }

        return LoanTransaction::query()
            ->join('loans', 'loans.id', '=', 'loan_transactions.loan_id')
            ->where('loans.cycle_id', $cycle->id)
            ->where('loan_transactions.cycle_month_id', $month->id)
            ->whereIn('loan_transactions.type', [
                LoanTransactionType::LatePenaltyDaily->value,
                LoanTransactionType::MissedInstallmentPenalty->value,
            ])
            ->distinct('loans.member_id')
            ->count('loans.member_id');
    }

    /**
     * Members with no savings recorded for the current month, which is what the
     * treasurer chases during the trading window.
     *
     * @return array<int, array<string, mixed>>
     */
    public function membersMissingSavings(Cycle $cycle, ?CycleMonth $month): array
    {
        if ($month === null) {
            return [];
        }

        return $cycle->members()
            ->active()
            ->whereDoesntHave('savingsTransactions', fn ($query) => $query->where('cycle_month_id', $month->id))
            ->get()
            ->map(fn (Member $member): array => [
                'id' => $member->id,
                'member_number' => $member->member_number,
                'full_name' => $member->full_name,
            ])
            ->values()
            ->all();
    }
}
