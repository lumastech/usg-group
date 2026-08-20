<?php

namespace App\Http\Controllers\App;

use App\Domain\Cycles\CurrentCycle;
use App\Domain\Reporting\CycleOverview;
use App\Http\Controllers\Controller;
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
    public function __invoke(CurrentCycle $currentCycle, CycleOverview $overview): Response
    {
        $cycle = $currentCycle->get();

        if ($cycle === null) {
            return Inertia::render('app/Dashboard', ['overview' => null, 'membersMissingSavings' => []]);
        }

        $today = Carbon::today();

        return Inertia::render('app/Dashboard', [
            'overview' => $overview->for($cycle, $today),
            'membersMissingSavings' => Inertia::defer(
                fn (): array => $overview->membersMissingSavings($cycle, $overview->currentMonth($cycle, $today)),
            ),
        ]);
    }
}
