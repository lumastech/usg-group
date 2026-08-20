<?php

namespace App\Http\Controllers\App;

use App\Domain\Cycles\CurrentCycle;
use App\Exceptions\DomainRuleException;
use App\Http\Requests\SocialFund\ApproveGrantClaimRequest;
use App\Http\Requests\SocialFund\StoreUnityBabyClaimRequest;
use App\Models\Member;
use App\Models\UnityBabyClaim;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The K500 grant for a child born to a member during the cycle.
 *
 * The three steps are identical to the funeral grant's, so they are inherited rather
 * than written twice — only the claim's own fields differ.
 */
class UnityBabyClaimController extends GrantClaimActionController
{
    public function store(StoreUnityBabyClaimRequest $request, CurrentCycle $currentCycle): RedirectResponse
    {
        $member = Member::findOrFail($request->integer('member_id'));

        try {
            $this->claims->assertClaimable($member);
        } catch (DomainRuleException $exception) {
            return back()->withErrors(['member_id' => $exception->getMessage()]);
        }

        UnityBabyClaim::create([
            'cycle_id' => $currentCycle->getOrFail()->id,
            'member_id' => $member->id,
            'child_name' => $request->input('child_name'),
            'born_on' => $request->date('born_on'),
            'claim_date' => $request->date('claim_date'),
            'amount_ngwee' => $this->claims->unityBabyGrantNgwee(),
            'note' => $request->input('note'),
        ]);

        return back()->with('success', 'The unity baby grant claim has gone to the committee.');
    }

    public function approve(ApproveGrantClaimRequest $request, UnityBabyClaim $claim): RedirectResponse
    {
        return $this->runApproval($request, $claim);
    }

    public function pay(ApproveGrantClaimRequest $request, UnityBabyClaim $claim): RedirectResponse
    {
        return $this->runPayment($request, $claim);
    }

    public function reject(Request $request, UnityBabyClaim $claim): RedirectResponse
    {
        return $this->runRejection($request, $claim);
    }
}
