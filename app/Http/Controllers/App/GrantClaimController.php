<?php

namespace App\Http\Controllers\App;

use App\Domain\Cycles\CurrentCycle;
use App\Domain\Reporting\SocialFundOverview;
use App\Enums\FuneralRelationship;
use App\Http\Controllers\Controller;
use App\Http\Resources\GrantClaimResource;
use App\Models\FuneralGrantClaim;
use App\Models\Member;
use App\Models\UnityBabyClaim;
use App\Support\Kwacha;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Both claim registers on one screen.
 *
 * The funeral form's relationship select is fed from FuneralRelationship, so the three
 * the constitution allows are the only three it can ever offer.
 */
class GrantClaimController extends Controller
{
    public function __construct(protected SocialFundOverview $overview) {}

    public function __invoke(Request $request, CurrentCycle $currentCycle): Response
    {
        $this->authorize('viewAny', FuneralGrantClaim::class);

        $cycle = $currentCycle->get();

        return Inertia::render('app/fund/Claims', [
            'funeralClaims' => $cycle === null ? [] : GrantClaimResource::collection(
                FuneralGrantClaim::query()->forCycle($cycle)
                    ->with('member', 'firstApprover', 'secondApprover')
                    ->latest('claim_date')->latest('id')->get(),
            ),
            'babyClaims' => $cycle === null ? [] : GrantClaimResource::collection(
                UnityBabyClaim::query()->forCycle($cycle)
                    ->with('member', 'firstApprover', 'secondApprover')
                    ->latest('claim_date')->latest('id')->get(),
            ),
            'members' => $cycle === null ? [] : $cycle->members()->active()->get()
                ->map(fn (Member $member): array => [
                    'value' => $member->id,
                    'label' => "{$member->member_number}. {$member->full_name}",
                ])->all(),
            'relationships' => array_map(
                fn (FuneralRelationship $relationship): array => [
                    'value' => $relationship->value,
                    'label' => $relationship->label(),
                ],
                FuneralRelationship::cases(),
            ),
            'balance_ngwee' => $cycle === null ? 0 : $this->overview->for($cycle)['balance_ngwee'],
            'rules' => [
                'funeral_grant_ngwee' => Kwacha::toNgwee(Kwacha::of(1_000)),
                'unity_baby_grant_ngwee' => Kwacha::toNgwee(Kwacha::of(500)),
            ],
            'abilities' => [
                'create' => $request->user()->can('create', FuneralGrantClaim::class),
            ],
        ]);
    }
}
