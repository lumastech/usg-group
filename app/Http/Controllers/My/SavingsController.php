<?php

namespace App\Http\Controllers\My;

use App\Domain\Cycles\CurrentCycle;
use App\Domain\Reporting\SavingsMatrix;
use App\Domain\Reporting\SavingsStatementPdf;
use App\Http\Controllers\Controller;
use App\Http\Resources\SavingsTransactionResource;
use App\Models\Member;
use App\Models\SavingsTransaction;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * A member's own savings.
 *
 * Scoped to the signed-in user's member record rather than gated by permission — this
 * portal only ever shows a member themselves, so there is no member id in the URL to
 * tamper with.
 */
class SavingsController extends Controller
{
    public function __construct(protected SavingsMatrix $matrix) {}

    public function __invoke(Request $request, CurrentCycle $currentCycle): Response
    {
        $member = $request->user()->member;
        $cycle = $currentCycle->get();

        if ($member === null || $cycle === null) {
            return Inertia::render('my/Savings', [
                'member' => null,
                'history' => [],
                'totals' => null,
                'transactions' => [],
                'cycleName' => $cycle?->name,
            ]);
        }

        $own = $this->matrix->forMember($cycle, $member);

        return Inertia::render('my/Savings', [
            'member' => [
                'id' => $member->id,
                'full_name' => $member->full_name,
                'member_number' => $member->member_number,
            ],
            'history' => $own['months'],
            'totals' => $own['totals'],
            'transactions' => SavingsTransactionResource::collection(
                SavingsTransaction::query()
                    ->where('member_id', $member->id)
                    ->with('cycleMonth')
                    ->latest('occurred_on')
                    ->latest('id')
                    ->get(),
            ),
            'cycleName' => $cycle->name,
        ]);
    }

    /** The same figures as a PDF the member can keep or show at a meeting. */
    public function statement(
        Request $request,
        CurrentCycle $currentCycle,
        SavingsStatementPdf $pdf,
    ): SymfonyResponse {
        $member = $request->user()->member;

        abort_if($member === null, 404);

        return $pdf->for($currentCycle->getOrFail(), $member);
    }
}
