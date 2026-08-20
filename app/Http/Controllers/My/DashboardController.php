<?php

namespace App\Http\Controllers\My;

use App\Domain\Cycles\CurrentCycle;
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
    public function __invoke(Request $request, CurrentCycle $currentCycle): Response
    {
        $member = Member::query()->where('user_id', $request->user()->id)->first();

        return Inertia::render('my/Dashboard', [
            'member' => $member === null ? null : [
                'id' => $member->id,
                'full_name' => $member->full_name,
                'member_number' => $member->member_number,
                'status' => $member->status,
                'joined_on' => $member->joined_on->toDateString(),
            ],
            'cycleName' => $currentCycle->get()?->name,
        ]);
    }
}
