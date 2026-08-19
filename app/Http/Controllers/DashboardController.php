<?php

namespace App\Http\Controllers;

use App\Domain\Reporting\CycleOverview;
use App\Enums\CycleStatus;
use App\Models\Cycle;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, CycleOverview $overview): Response
    {
        $cycle = Cycle::query()
            ->whereIn('status', [CycleStatus::Active, CycleStatus::Closing])
            ->latest('starts_on')
            ->first() ?? Cycle::latest('starts_on')->first();

        if ($cycle === null) {
            return Inertia::render('Dashboard', ['overview' => null, 'membersMissingSavings' => []]);
        }

        $today = Carbon::today();
        $month = $overview->currentMonth($cycle, $today);

        return Inertia::render('Dashboard', [
            'overview' => $overview->for($cycle, $today),
            'membersMissingSavings' => Inertia::defer(
                fn (): array => $overview->membersMissingSavings($cycle, $month)
            ),
        ]);
    }
}
