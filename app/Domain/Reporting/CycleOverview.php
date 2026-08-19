<?php

namespace App\Domain\Reporting;

use App\Enums\MemberStatus;
use App\Models\Cycle;
use App\Models\CycleMonth;
use App\Models\InterestAllocation;
use App\Models\Member;
use App\Models\SavingsTransaction;
use App\Support\Kwacha;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Group-wide figures for the administration dashboard.
 *
 * Loan and social fund totals are reported as null until those ledgers exist, so the
 * dashboard can show "not yet tracked" rather than a misleading zero.
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

            // Awaiting the loans and social fund modules.
            'loans_outstanding' => null,
            'social_fund_balance' => null,
            'negative_net_value_members' => null,
        ];
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
