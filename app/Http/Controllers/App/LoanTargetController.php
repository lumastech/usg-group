<?php

namespace App\Http\Controllers\App;

use App\Domain\Cycles\CurrentCycle;
use App\Domain\Loans\BorrowingTargetTracker;
use App\Http\Controllers\Controller;
use App\Models\Loan;
use App\Support\Kwacha;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Progress against the borrowing target every member carries for the cycle.
 *
 * The group's income is the interest its members pay, so a cycle where nobody borrows
 * earns nobody anything. The target is talked about and tracked; it blocks nothing.
 */
class LoanTargetController extends Controller
{
    public function __construct(protected BorrowingTargetTracker $tracker) {}

    public function __invoke(Request $request, CurrentCycle $currentCycle): Response
    {
        $this->authorize('viewAny', Loan::class);

        $cycle = $currentCycle->get();
        $rows = $cycle === null ? collect() : $this->tracker->for($cycle);

        return Inertia::render('app/loans/Targets', [
            'rows' => $rows->all(),
            'target_ngwee' => $cycle === null ? 0 : Kwacha::toNgwee($cycle->borrowing_target_ngwee),
            'totals' => [
                'borrowed_ngwee' => $rows->sum('borrowed_ngwee'),
                'balance_to_borrow_ngwee' => $rows->sum('balance_to_borrow_ngwee'),
                'under_target' => $rows->where('under_target', true)->count(),
            ],
        ]);
    }
}
