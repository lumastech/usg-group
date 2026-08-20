<?php

namespace App\Http\Controllers\My;

use App\Domain\Cycles\CurrentCycle;
use App\Domain\Declarations\DeclarationService;
use App\Domain\Declarations\DeclarationWindow;
use App\Http\Controllers\Controller;
use App\Models\Member;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Landing page of the member portal: one member's own position in the cycle.
 */
class DashboardController extends Controller
{
    public function __construct(
        protected DeclarationWindow $window,
        protected DeclarationService $declarations,
    ) {}

    public function __invoke(Request $request, CurrentCycle $currentCycle): Response
    {
        $member = Member::query()->where('user_id', $request->user()->id)->first();
        $cycle = $currentCycle->get();
        $month = $cycle?->monthFor(now());

        return Inertia::render('my/Dashboard', [
            'member' => $member === null ? null : [
                'id' => $member->id,
                'full_name' => $member->full_name,
                'member_number' => $member->member_number,
                'status' => $member->status,
                'joined_on' => $member->joined_on->toDateString(),
            ],
            'cycleName' => $cycle?->name,
            /* The member's own answer to "is there anything I have to do today?" */
            'monthWindow' => $month === null ? null : [
                ...$this->window->payload($month),
                'has_declared' => $member !== null
                    && $this->declarations->find($member, $month) !== null,
            ],
        ]);
    }
}
