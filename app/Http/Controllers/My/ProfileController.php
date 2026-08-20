<?php

namespace App\Http\Controllers\My;

use App\Http\Controllers\Controller;
use App\Http\Requests\Members\UpdateOwnProfileRequest;
use App\Http\Resources\MemberResource;
use App\Models\Member;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A member's own record in the member portal.
 *
 * Members keep their phone number and address current themselves; everything else
 * is the committee's to amend. Each edit is written to the activity log, so the
 * register still shows who changed a number and when.
 */
class ProfileController extends Controller
{
    public function show(Request $request): Response
    {
        $member = $this->member($request);

        return Inertia::render('my/Profile', [
            'member' => $member === null
                ? null
                : new MemberResource($member->loadMissing('nextOfKin', 'user')),
        ]);
    }

    public function update(UpdateOwnProfileRequest $request, Member $member): RedirectResponse
    {
        $member->update($request->validated());

        activity()
            ->performedOn($member)
            ->causedBy($request->user())
            ->event('contact_details_updated')
            ->log('Contact details updated by the member');

        return back()->with('success', 'Your details have been updated.');
    }

    protected function member(Request $request): ?Member
    {
        return Member::query()->where('user_id', $request->user()->id)->first();
    }
}
