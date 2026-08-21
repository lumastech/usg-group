<?php

namespace App\Http\Controllers\App;

use App\Domain\Cycles\CurrentCycle;
use App\Domain\Declarations\DeclarationService;
use App\Domain\Declarations\DeclarationWindow;
use App\Domain\Reporting\CycleOverview;
use App\Domain\Trading\TradingSessionService;
use App\Http\Controllers\Controller;
use App\Models\CycleMonth;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Landing page of the committee portal.
 *
 * Every figure comes from the reporting service rather than being assembled here,
 * so the dashboard and the reports agree by construction.
 *
 * Widgets are permission-aware, and they are aware of it on the server: a signatory
 * without `loans.view` is not merely shown fewer tiles — the lending, risk and target
 * sections are stripped from the props before the page is rendered, so the figures
 * never reach their browser. The same permission names drive the sidebar, so a section
 * a user cannot navigate to is a section they cannot see the totals of either.
 */
class DashboardController extends Controller
{
    public function __construct(
        protected DeclarationWindow $window,
        protected DeclarationService $declarations,
        protected TradingSessionService $sessions,
    ) {}

    public function __invoke(Request $request, CurrentCycle $currentCycle, CycleOverview $overview): Response
    {
        $cycle = $currentCycle->get();
        $widgets = $this->widgetsFor($request);

        if ($cycle === null) {
            return Inertia::render('app/Dashboard', [
                'overview' => null,
                'membersMissingSavings' => [],
                'monthWindow' => null,
                'widgets' => $widgets,
            ]);
        }

        $today = Carbon::today();

        return Inertia::render('app/Dashboard', [
            'overview' => $this->visibleSections($overview->for($cycle, $today), $widgets),
            'widgets' => $widgets,
            'membersMissingSavings' => Inertia::defer(
                fn (): array => $overview->membersMissingSavings($cycle, $overview->currentMonth($cycle, $today)),
            ),
            /* Where the month is and what it is still waiting for: the two questions
               the committee opens the dashboard to answer during trading week. */
            'monthWindow' => $this->monthWindow($cycle->monthFor($today)),
        ]);
    }

    /**
     * Which widgets this user may see, keyed the way the page reads them.
     *
     * @return array<string, bool>
     */
    protected function widgetsFor(Request $request): array
    {
        $user = $request->user();

        return [
            'savings' => $user->can('savings.view'),
            'lending' => $user->can('loans.view'),
            'risk' => $user->can('loans.view'),
            'target' => $user->can('loans.view'),
            'fund' => $user->can('fund.view'),
            'compliance' => $user->can('reports.view'),
            'shareout' => $user->can('payouts.approve') || $user->can('payouts.execute'),
        ];
    }

    /**
     * Drops the sections the user holds no permission for.
     *
     * @param  array<string, mixed>  $overview
     * @param  array<string, bool>  $widgets
     * @return array<string, mixed>
     */
    protected function visibleSections(array $overview, array $widgets): array
    {
        foreach (['lending' => 'lending', 'risk' => 'risk', 'target' => 'target', 'fund' => 'fund', 'compliance' => 'compliance'] as $section => $widget) {
            if (! ($widgets[$widget] ?? false)) {
                unset($overview[$section]);
            }
        }

        /* The loan and fund figures inside the money block travel with their sections. */
        if (! $widgets['lending']) {
            unset($overview['money']['loans_outstanding'], $overview['money']['cash_position'], $overview['money']['cash_position_ngwee']);
        }

        if (! $widgets['fund']) {
            unset($overview['money']['social_fund_balance'], $overview['money']['social_fund_balance_ngwee']);
        }

        return $overview;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function monthWindow(?CycleMonth $month): ?array
    {
        if ($month === null) {
            return null;
        }

        $session = $this->sessions->find($month);

        return [
            ...$this->window->payload($month),
            'missing_declarations' => $this->declarations->missingFor($month)->count(),
            'session_status' => $session?->status,
            'session_id' => $session?->id,
        ];
    }
}
