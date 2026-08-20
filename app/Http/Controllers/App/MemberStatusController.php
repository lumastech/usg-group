<?php

namespace App\Http\Controllers\App;

use App\Domain\Members\MemberStatusService;
use App\Enums\MemberStatus;
use App\Exceptions\InvalidStatusTransitionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Members\ChangeMemberStatusRequest;
use App\Models\Member;
use Illuminate\Http\RedirectResponse;

/**
 * Records a change to a member's standing.
 *
 * All the rules live in MemberStatusService; this only translates its refusal into
 * a validation error so the dialog shows it against the status field.
 */
class MemberStatusController extends Controller
{
    public function __invoke(
        ChangeMemberStatusRequest $request,
        Member $member,
        MemberStatusService $statuses,
    ): RedirectResponse {
        $status = MemberStatus::from($request->string('status')->toString());

        try {
            $statuses->transition($member, $status, $request->safe()->except('status'), $request->user());
        } catch (InvalidStatusTransitionException $exception) {
            return back()->withErrors(['status' => $exception->getMessage()]);
        }

        return back()->with('success', "{$member->full_name} is now recorded as {$status->label()}.");
    }
}
