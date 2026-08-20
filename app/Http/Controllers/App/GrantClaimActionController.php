<?php

namespace App\Http\Controllers\App;

use App\Domain\SocialFund\GrantClaimService;
use App\Exceptions\DomainRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\SocialFund\ApproveGrantClaimRequest;
use App\Models\FuneralGrantClaim;
use App\Models\UnityBabyClaim;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The three steps a funeral claim and a unity baby claim share.
 *
 * Both grants are approved by two committee members, paid by two, or rejected with a
 * reason. Only the claim's own fields differ, so the subclasses carry the model-typed
 * entry points and the sequence itself lives here once.
 */
abstract class GrantClaimActionController extends Controller
{
    public function __construct(protected GrantClaimService $claims) {}

    /** @param  FuneralGrantClaim|UnityBabyClaim  $claim */
    protected function runApproval(ApproveGrantClaimRequest $request, $claim): RedirectResponse
    {
        $actor = $request->user()->member;

        if ($actor === null) {
            return back()->withErrors(['approver_email' => 'Your login is not linked to a member record.']);
        }

        try {
            $this->claims->approve($claim->load('member'), $actor->load('user'), $request->approver());
        } catch (DomainRuleException $exception) {
            return back()->withErrors(['approver_email' => $exception->getMessage()]);
        }

        return back()->with('success', 'The claim now carries two committee signatures.');
    }

    /** @param  FuneralGrantClaim|UnityBabyClaim  $claim */
    protected function runPayment(ApproveGrantClaimRequest $request, $claim): RedirectResponse
    {
        $actor = $request->user()->member;

        if ($actor === null) {
            return back()->withErrors(['approver_email' => 'Your login is not linked to a member record.']);
        }

        try {
            $this->claims->pay(
                $claim->load('member', 'cycle'),
                $actor->load('user'),
                $request->approver(),
                $request->filled('occurred_on') ? Carbon::parse($request->string('occurred_on')->toString()) : null,
            );
        } catch (DomainRuleException $exception) {
            return back()->withErrors(['approver_email' => $exception->getMessage()]);
        }

        return back()->with('success', 'The grant has been paid and the fund debited.');
    }

    /** @param  FuneralGrantClaim|UnityBabyClaim  $claim */
    protected function runRejection(Request $request, $claim): RedirectResponse
    {
        $this->authorize('reject', $claim);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $actor = $request->user()->member;

        if ($actor === null) {
            return back()->withErrors(['reason' => 'Your login is not linked to a member record.']);
        }

        try {
            $this->claims->reject($claim, $actor, $validated['reason']);
        } catch (DomainRuleException $exception) {
            return back()->withErrors(['reason' => $exception->getMessage()]);
        }

        return back()->with('success', 'The claim has been rejected.');
    }
}
