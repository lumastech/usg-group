<?php

namespace App\Http\Controllers\App;

use App\Domain\Cycles\CurrentCycle;
use App\Domain\Reporting\LoanMatrix;
use App\Http\Controllers\Controller;
use App\Models\Loan;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The workbook's LOANS sheet: members down the side, months across, balances inside. */
class LoanMatrixController extends Controller
{
    public function __construct(protected LoanMatrix $matrix) {}

    public function __invoke(Request $request, CurrentCycle $currentCycle): Response
    {
        $this->authorize('viewAny', Loan::class);

        $cycle = $currentCycle->get();
        $through = $request->integer('through') ?: null;

        return Inertia::render('app/loans/Matrix', [
            'matrix' => $cycle === null ? null : $this->matrix->for($cycle, $through),
            'cycle' => $cycle === null ? null : ['id' => $cycle->id, 'name' => $cycle->name],
            'filters' => ['through' => $through],
        ]);
    }
}
