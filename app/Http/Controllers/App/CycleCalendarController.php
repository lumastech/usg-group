<?php

namespace App\Http\Controllers\App;

use App\Domain\Cycles\CurrentCycle;
use App\Domain\Cycles\CycleCalendar;
use App\Domain\Cycles\CycleMonthPlanner;
use App\Domain\Declarations\DeclarationWindow;
use App\Exceptions\DomainRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateCycleMonthRequest;
use App\Models\Cycle;
use App\Models\CycleMonth;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The cycle's calendar, as the committee may change it.
 *
 * Every window in the portal is read from these rows, so this screen is the only place
 * a declaration period is reopened, a trading day moved or a disbursement re-dated. It
 * changes dates, never rules: the savings increments, the lockdown and the eligibility
 * checks all still run, they simply run on the days set here.
 */
class CycleCalendarController extends Controller
{
    public function __construct(
        protected CycleCalendar $calendar,
        protected DeclarationWindow $window,
    ) {}

    public function index(Request $request, CurrentCycle $currentCycle): Response
    {
        $cycle = $currentCycle->get();

        return Inertia::render('app/settings/Calendar', [
            'cycle' => $cycle === null ? null : [
                'id' => $cycle->id,
                'name' => $cycle->name,
                'status' => $cycle->status,
                'status_label' => $cycle->status->label(),
                'starts_on' => $cycle->starts_on->toDateString(),
                'ends_on' => $cycle->ends_on->toDateString(),
                'weekend_policy_label' => $cycle->weekend_trading_policy->label(),
            ],
            'months' => $cycle === null ? [] : $this->months($cycle),
            'constitution' => [
                'declarations_open_day' => CycleMonthPlanner::DECLARATIONS_OPEN_DAY,
                'declarations_open_hour' => CycleMonthPlanner::DECLARATIONS_OPEN_HOUR,
                'declarations_close_day' => CycleMonthPlanner::DECLARATIONS_CLOSE_DAY,
                'trading_start_day' => CycleMonthPlanner::TRADING_START_DAY,
                'disbursement_day' => CycleMonthPlanner::DISBURSEMENT_DAY,
            ],
        ]);
    }

    public function update(UpdateCycleMonthRequest $request, CycleMonth $month): RedirectResponse
    {
        try {
            $this->calendar->reschedule(
                $month,
                $request->scheduleDate('declarations_open_at'),
                $request->scheduleDate('declarations_close_at'),
                $request->scheduleDate('trading_starts_on'),
                $request->scheduleDate('trading_concludes_on'),
                $request->scheduleDate('disbursement_on'),
            );
        } catch (DomainRuleException $exception) {
            return back()->withErrors(['declarations_close_at' => $exception->getMessage()]);
        }

        return back()->with('success', "{$month->label()} has been re-dated.");
    }

    /** Puts one month back on the dates the constitution sets. */
    public function reset(CycleMonth $month): RedirectResponse
    {
        try {
            $this->calendar->resetToConstitution($month);
        } catch (DomainRuleException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', "{$month->label()} is back on the constitution's dates.");
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function months(Cycle $cycle): array
    {
        $today = Carbon::now();

        return $cycle->months->map(fn (CycleMonth $month): array => [
            'id' => $month->id,
            'sequence' => $month->sequence,
            'label' => $month->label(),
            'month' => $month->month->toDateString(),
            'status' => $month->status,
            'window' => $this->window->state($month, $today),
            'is_current' => $month->month->isSameMonth($today),
            'editable' => $this->calendar->isReschedulable($month),
            'declarations_open_at' => $month->declarations_open_at->format('Y-m-d\TH:i'),
            'declarations_close_at' => $month->declarations_close_at->format('Y-m-d\TH:i'),
            'trading_starts_on' => $month->trading_starts_on->toDateString(),
            'trading_concludes_on' => $month->trading_concludes_on->toDateString(),
            'disbursement_on' => $month->disbursement_on->toDateString(),
        ])->all();
    }
}
