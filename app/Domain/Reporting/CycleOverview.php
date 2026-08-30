<?php

namespace App\Domain\Reporting;

use App\Domain\Loans\BorrowingTargetTracker;
use App\Domain\SocialFund\SocialFundContributions;
use App\Domain\SocialFund\SocialFundLedger;
use App\Enums\DeclarationStatus;
use App\Enums\LoanScheduleItemStatus;
use App\Enums\LoanStatus;
use App\Enums\LoanTransactionType;
use App\Enums\MemberStatus;
use App\Models\Cycle;
use App\Models\CycleMonth;
use App\Models\Declaration;
use App\Models\InterestAllocation;
use App\Models\Loan;
use App\Models\LoanScheduleItem;
use App\Models\LoanTransaction;
use App\Models\Member;
use App\Models\SavingsTransaction;
use App\Support\Kwacha;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Group-wide figures for the administration dashboard.
 *
 * Every module's numbers are read here, off the ledgers rather than off the cached
 * snapshots, so the dashboard is right on the trading day even when a rebuild is
 * overdue — and so the dashboard and the reports agree by construction.
 *
 * The sections are deliberately separable. The dashboard renders a widget only when
 * the signed-in user holds the permission that owns it, and the controller asks for
 * just those sections, so a signatory without `loans.view` never has lending figures
 * sent to their browser in the first place.
 */
class CycleOverview
{
    public function __construct(
        protected SocialFundLedger $fund,
        protected SocialFundContributions $contributions,
        protected BorrowingTargetTracker $targets,
        protected NegativeNetValueProjection $risk,
    ) {}

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
            'fund' => $this->fundSummary($cycle),
            'target' => $this->targetSummary($cycle),
            'risk' => $this->riskSummary($cycle),
            'compliance' => $this->complianceSummary($cycle, $currentMonth),
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

        $fundBalance = Kwacha::toNgwee($this->fund->balance($cycle));
        $cashPosition = $totalSavings + $totalInterest - $this->outstandingLoansNgwee($cycle);

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

            'social_fund_balance' => Kwacha::format($fundBalance),
            'social_fund_balance_ngwee' => $fundBalance,

            /* What the group is not currently holding out on loan. The Social Fund is
               reported beside it rather than inside it: that money is the group's, for
               bereavements and celebrations, and was never part of anyone's savings. */
            'cash_position' => Kwacha::format($cashPosition),
            'cash_position_ngwee' => $cashPosition,
        ];
    }

    /**
     * The Social Fund: what it holds, and who has not yet paid into it.
     *
     * @return array<string, mixed>
     */
    protected function fundSummary(Cycle $cycle): array
    {
        $balance = Kwacha::toNgwee($this->fund->balance($cycle));
        $outstanding = $this->contributions->outstanding($cycle);

        return [
            'balance_ngwee' => $balance,
            'balance' => Kwacha::format($balance),
            'contributions_outstanding' => $outstanding->count(),
        ];
    }

    /**
     * Progress against the K50,000 each member is meant to borrow across the cycle.
     *
     * The target is a goal the committee talks about, never a rule — the group's income
     * is the interest its members pay, so a cycle where nobody borrows earns nobody
     * anything. Falling short of it blocks nothing.
     *
     * @return array<string, mixed>
     */
    protected function targetSummary(Cycle $cycle): array
    {
        $rows = $this->targets->for($cycle);
        $borrowed = (int) $rows->sum('borrowed_ngwee');
        $target = (int) $rows->sum('target_ngwee');

        return [
            'target_ngwee' => $target,
            'borrowed_ngwee' => $borrowed,
            'shortfall_ngwee' => max(0, $target - $borrowed),
            'progress_percent' => $target === 0 ? 100 : (int) round($borrowed / $target * 100),
            'members_at_target' => $rows->reject(fn (array $row): bool => $row['under_target'])->count(),
            'members_under_target' => $rows->filter(fn (array $row): bool => $row['under_target'])->count(),
        ];
    }

    /**
     * Members whose loans have outrun their savings.
     *
     * A count and a total only. The full month-by-month catch-up plan lives on /app/risk,
     * because working it out for thirty members is not what the dashboard is opened for.
     *
     * @return array<string, mixed>
     */
    protected function riskSummary(Cycle $cycle): array
    {
        $projection = $this->risk->for($cycle);

        return [
            'members' => $projection['totals']['members'],
            'shortfall_ngwee' => $projection['totals']['shortfall_ngwee'],
            'minimum_monthly_ngwee' => $projection['totals']['minimum_monthly_ngwee'],
            'horizon_months' => $projection['horizon_months'],
            /* The three worst, so the tile can name somebody rather than only count. */
            'worst' => array_slice($projection['rows'], 0, 3),
        ];
    }

    /**
     * What the committee chases: dues unpaid, declarations that came in late, and
     * installments the schedule says were missed.
     *
     * @return array<string, mixed>
     */
    protected function complianceSummary(Cycle $cycle, ?CycleMonth $month): array
    {
        $monthIds = $cycle->months()->pluck('id');

        return [
            'unpaid_contributions' => $this->contributions->outstanding($cycle)->count(),
            'unpaid_joining_fees' => $cycle->members()->where('joining_fee_paid', false)->count(),
            'late_declarations' => (int) Declaration::query()
                ->acrossCycles()
                ->whereIn('cycle_month_id', $monthIds)
                ->where('is_late', true)
                ->count(),
            'late_declarations_this_month' => $month === null ? 0 : (int) Declaration::query()
                ->acrossCycles()
                ->where('cycle_month_id', $month->id)
                ->where('is_late', true)
                ->count(),
            /* Every declaration made for the month, whatever stage it has reached —
               approving one must not make it vanish from the count of who declared. */
            'declarations_submitted_this_month' => $month === null ? 0 : (int) Declaration::query()
                ->acrossCycles()
                ->where('cycle_month_id', $month->id)
                ->count(),
            'declarations_awaiting_approval' => $month === null ? 0 : (int) Declaration::query()
                ->acrossCycles()
                ->where('cycle_month_id', $month->id)
                ->where('status', DeclarationStatus::Submitted->value)
                ->count(),
            'missed_installments' => (int) LoanScheduleItem::query()
                ->join('loans', 'loans.id', '=', 'loan_schedule_items.loan_id')
                ->where('loans.cycle_id', $cycle->id)
                ->where('loan_schedule_items.status', LoanScheduleItemStatus::Missed->value)
                ->count(),
        ];
    }

    /** What the fund has lent out, what it is about to lend, and who is behind.
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
