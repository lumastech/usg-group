<?php

namespace App\Http\Controllers\App;

use App\Domain\Cycles\CurrentCycle;
use App\Exceptions\DomainRuleException;
use App\Http\Requests\SocialFund\ApproveGrantClaimRequest;
use App\Http\Requests\SocialFund\StoreFuneralGrantClaimRequest;
use App\Models\FuneralGrantClaim;
use App\Models\Member;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The K1,000 funeral grant.
 *
 * Which deaths qualify is settled by the type of the relationship column, not by
 * anything here: App\Enums\FuneralRelationship has three cases and the form request
 * validates against it, so a claim for a sibling never reaches this controller.
 */
class FuneralGrantClaimController extends GrantClaimActionController
{
    public function store(StoreFuneralGrantClaimRequest $request, CurrentCycle $currentCycle): RedirectResponse
    {
        $member = Member::findOrFail($request->integer('member_id'));

        try {
            $this->claims->assertClaimable($member);
        } catch (DomainRuleException $exception) {
            return back()->withErrors(['member_id' => $exception->getMessage()]);
        }

        FuneralGrantClaim::create([
            'cycle_id' => $currentCycle->getOrFail()->id,
            'member_id' => $member->id,
            'deceased_name' => $request->string('deceased_name')->toString(),
            'relationship' => $request->relationship(),
            'claim_date' => $request->date('claim_date'),
            'amount_ngwee' => $this->claims->funeralGrantNgwee(),
            'note' => $request->input('note'),
        ]);

        return back()->with('success', 'The funeral grant claim has gone to the committee.');
    }

    public function approve(ApproveGrantClaimRequest $request, FuneralGrantClaim $claim): RedirectResponse
    {
        return $this->runApproval($request, $claim);
    }

    public function pay(ApproveGrantClaimRequest $request, FuneralGrantClaim $claim): RedirectResponse
    {
        return $this->runPayment($request, $claim);
    }

    public function reject(Request $request, FuneralGrantClaim $claim): RedirectResponse
    {
        return $this->runRejection($request, $claim);
    }
}
