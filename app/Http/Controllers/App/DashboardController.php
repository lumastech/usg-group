<?php

namespace App\Http\Controllers\App;

use App\Domain\Cycles\CurrentCycle;
use App\Domain\Declarations\DeclarationService;
use App\Domain\Declarations\DeclarationWindow;
use App\Domain\Reporting\CycleOverview;
use App\Domain\Trading\TradingSessionService;
use App\Http\Controllers\Controller;
use App\Models\CycleMonth;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Landing page of the committee portal.
 *
 * Every figure comes from the reporting service rather than being assembled here,
 * so the dashboard and the reports agree by construction.
 */
class DashboardController extends Controller
{
    public function __construct(
        protected DeclarationWindow $window,
        protected DeclarationService $declarations,
        protected TradingSessionService $sessions,
    ) {}

    public function __invoke(CurrentCycle $currentCycle, CycleOverview $overview): Response
    {
        $cycle = $currentCycle->get();

        if ($cycle === null) {
            return Inertia::render('app/Dashboard', [
                'overview' => null,
                'membersMissingSavings' => [],
                'monthWindow' => null,
            ]);
        }

        $today = Carbon::today();

        return Inertia::render('app/Dashboard', [
            'overview' => $overview->for($cycle, $today),
            'membersMissingSavings' => Inertia::defer(
                fn (): array => $overview->membersMissingSavings($cycle, $overview->currentMonth($cycle, $today)),
            ),
            /* Where the month is and what it is still waiting for: the two questions
               the committee opens the dashboard to answer during trading week. */
            'monthWindow' => $this->monthWindow($cycle->monthFor($today)),
        ]);
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
