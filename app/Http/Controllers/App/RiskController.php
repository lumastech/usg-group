<?php

namespace App\Http\Controllers\App;

use App\Domain\Cycles\CurrentCycle;
use App\Domain\Reporting\NegativeNetValueProjection;
use App\Http\Controllers\Controller;
use App\Models\Loan;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Members whose loans have outrun their savings.
 *
 * The workbook's "Min Repayments-Negative NV" sheet, and the page behind the
 * dashboard's risk tile: who is under water, by how much, and what they must pay each
 * month for the next three to be level again at the cycle's own 5%.
 */
class RiskController extends Controller
{
    public function __construct(protected NegativeNetValueProjection $projection) {}

    public function __invoke(Request $request, CurrentCycle $currentCycle): Response
    {
        $this->authorize('viewAny', Loan::class);

        $cycle = $currentCycle->get();

        return Inertia::render('app/Risk', [
            'cycle' => $cycle === null ? null : [
                'id' => $cycle->id,
                'name' => $cycle->name,
                'monthly_interest_bps' => $cycle->monthly_interest_bps,
            ],
            'projection' => $cycle === null ? null : $this->projection->for($cycle),
        ]);
    }
}
