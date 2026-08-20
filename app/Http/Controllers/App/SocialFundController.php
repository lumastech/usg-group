<?php

namespace App\Http\Controllers\App;

use App\Domain\Cycles\CurrentCycle;
use App\Domain\Reporting\SocialFundOverview;
use App\Http\Controllers\Controller;
use App\Http\Resources\SocialFundTransactionResource;
use App\Models\DiasporaApportionment;
use App\Models\FuneralGrantClaim;
use App\Models\SocialFundTransaction;
use App\Models\UnityBabyClaim;
use App\Support\Kwacha;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Social Fund dashboard.
 *
 * The balance, the month-by-month movement and the chaser list of unpaid contributions
 * all come from SocialFundOverview, so this screen cannot disagree with the ledger or
 * with the exported sheet.
 */
class SocialFundController extends Controller
{
    public function __construct(protected SocialFundOverview $overview) {}

    public function index(Request $request, CurrentCycle $currentCycle): Response
    {
        $this->authorize('viewAny', SocialFundTransaction::class);

        $cycle = $currentCycle->get();

        if ($cycle === null) {
            return Inertia::render('app/fund/Index', [
                'overview' => null,
                'unpaid' => [],
                'recent' => [],
                'openClaims' => 0,
                'pendingTransfers' => 0,
                'rules' => null,
                'abilities' => $this->abilities($request),
            ]);
        }

        return Inertia::render('app/fund/Index', [
            'overview' => $this->overview->for($cycle),
            'unpaid' => $this->overview->unpaidContributions($cycle),
            'recent' => SocialFundTransactionResource::collection(
                SocialFundTransaction::query()
                    ->forCycle($cycle)
                    ->with('member', 'cycleMonth', 'recordedBy', 'secondApprover')
                    ->latest('occurred_on')
                    ->latest('id')
                    ->limit(10)
                    ->get(),
            ),
            'openClaims' => FuneralGrantClaim::query()->forCycle($cycle)->whereIn('status', ['submitted', 'approved'])->count()
                + UnityBabyClaim::query()->forCycle($cycle)->whereIn('status', ['submitted', 'approved'])->count(),
            'pendingTransfers' => DiasporaApportionment::query()->forCycle($cycle)
                ->withCount(['items as pending_count' => fn ($query) => $query->where('status', 'pending')])
                ->get()
                ->sum('pending_count'),
            'rules' => [
                'contribution_ngwee' => Kwacha::toNgwee($cycle->social_fund_contribution_ngwee),
                'funeral_grant_ngwee' => Kwacha::toNgwee(Kwacha::of(1_000)),
                'unity_baby_grant_ngwee' => Kwacha::toNgwee(Kwacha::of(500)),
            ],
            'abilities' => $this->abilities($request),
        ]);
    }

    /**
     * @return array<string, bool>
     */
    protected function abilities(Request $request): array
    {
        $user = $request->user();

        return [
            'record' => $user->can('create', SocialFundTransaction::class),
            'approveOutflow' => $user->can('approveOutflow', SocialFundTransaction::class),
            'apportion' => $user->can('create', DiasporaApportionment::class),
        ];
    }
}
