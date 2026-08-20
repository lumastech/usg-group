<?php

namespace App\Http\Controllers\My;

use App\Domain\Cycles\CurrentCycle;
use App\Domain\SocialFund\SocialFundContributions;
use App\Enums\FuneralRelationship;
use App\Http\Controllers\Controller;
use App\Http\Requests\SocialFund\StoreFuneralGrantClaimRequest;
use App\Http\Requests\SocialFund\StoreUnityBabyClaimRequest;
use App\Http\Resources\GrantClaimResource;
use App\Http\Resources\SocialFundTransactionResource;
use App\Models\FuneralGrantClaim;
use App\Models\SocialFundTransaction;
use App\Models\UnityBabyClaim;
use App\Support\Kwacha;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A member's own corner of the Social Fund.
 *
 * Scoped to the signed-in user's member record, so there is no id in the URL: the
 * member sees whether their K250 is in, what they have claimed and where each claim
 * has got to, and can raise a new claim for themselves only.
 */
class SocialFundController extends Controller
{
    public function __construct(protected SocialFundContributions $contributions) {}

    public function show(Request $request, CurrentCycle $currentCycle): Response
    {
        $member = $request->user()->member;
        $cycle = $currentCycle->get();

        if ($member === null || $cycle === null) {
            return Inertia::render('my/Fund', [
                'member' => null,
                'contribution' => null,
                'entries' => [],
                'funeralClaims' => [],
                'babyClaims' => [],
                'relationships' => [],
                'rules' => null,
            ]);
        }

        return Inertia::render('my/Fund', [
            'member' => [
                'id' => $member->id,
                'full_name' => $member->full_name,
                'member_number' => $member->member_number,
            ],
            'contribution' => [
                'paid' => $this->contributions->hasPaid($member),
                'expected_ngwee' => Kwacha::toNgwee($cycle->social_fund_contribution_ngwee),
            ],
            'entries' => SocialFundTransactionResource::collection(
                SocialFundTransaction::query()->forCycle($cycle)
                    ->where('member_id', $member->id)
                    ->with('cycleMonth')
                    ->orderBy('occurred_on')->orderBy('id')->get(),
            ),
            'funeralClaims' => GrantClaimResource::collection(
                $member->funeralGrantClaims()->with('member')->latest('claim_date')->get(),
            ),
            'babyClaims' => GrantClaimResource::collection(
                $member->unityBabyClaims()->with('member')->latest('claim_date')->get(),
            ),
            'relationships' => array_map(
                fn (FuneralRelationship $relationship): array => [
                    'value' => $relationship->value,
                    'label' => $relationship->label(),
                ],
                FuneralRelationship::cases(),
            ),
            'rules' => [
                'contribution_ngwee' => Kwacha::toNgwee($cycle->social_fund_contribution_ngwee),
                'funeral_grant_ngwee' => Kwacha::toNgwee(Kwacha::of(1_000)),
                'unity_baby_grant_ngwee' => Kwacha::toNgwee(Kwacha::of(500)),
            ],
        ]);
    }

    /** A member raising their own funeral grant claim. */
    public function storeFuneralClaim(StoreFuneralGrantClaimRequest $request, CurrentCycle $currentCycle): RedirectResponse
    {
        $member = $request->user()->member;

        if ($member === null || $member->id !== $request->integer('member_id')) {
            return back()->withErrors(['member_id' => 'You can only claim for yourself here.']);
        }

        FuneralGrantClaim::create([
            'cycle_id' => $currentCycle->getOrFail()->id,
            'member_id' => $member->id,
            'deceased_name' => $request->string('deceased_name')->toString(),
            'relationship' => $request->relationship(),
            'claim_date' => $request->date('claim_date'),
            'amount_ngwee' => Kwacha::toNgwee(Kwacha::of(1_000)),
            'note' => $request->input('note'),
        ]);

        return back()->with('success', 'Your claim has gone to the committee.');
    }

    /** A member raising their own unity baby claim. */
    public function storeBabyClaim(StoreUnityBabyClaimRequest $request, CurrentCycle $currentCycle): RedirectResponse
    {
        $member = $request->user()->member;

        if ($member === null || $member->id !== $request->integer('member_id')) {
            return back()->withErrors(['member_id' => 'You can only claim for yourself here.']);
        }

        UnityBabyClaim::create([
            'cycle_id' => $currentCycle->getOrFail()->id,
            'member_id' => $member->id,
            'child_name' => $request->input('child_name'),
            'born_on' => $request->date('born_on'),
            'claim_date' => $request->date('claim_date'),
            'amount_ngwee' => Kwacha::toNgwee(Kwacha::of(500)),
            'note' => $request->input('note'),
        ]);

        return back()->with('success', 'Your claim has gone to the committee.');
    }
}
