<?php

namespace App\Http\Controllers\App;

use App\Domain\Members\MemberInviter;
use App\Exceptions\DomainRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Members\InviteMemberRequest;
use App\Models\Member;
use Illuminate\Http\RedirectResponse;

/** Attaches a portal login to a member and emails them the invitation. */
class MemberInviteController extends Controller
{
    public function __invoke(InviteMemberRequest $request, Member $member, MemberInviter $inviter): RedirectResponse
    {
        try {
            $inviter->invite($member, $request->string('email')->toString());
        } catch (DomainRuleException $exception) {
            return back()->withErrors(['email' => $exception->getMessage()]);
        }

        return back()->with('success', "Invitation sent to {$request->string('email')}.");
    }
}
