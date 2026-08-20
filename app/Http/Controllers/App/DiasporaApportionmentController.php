<?php

namespace App\Http\Controllers\App;

use App\Domain\Cycles\CurrentCycle;
use App\Domain\Reporting\SocialFundOverview;
use App\Domain\SocialFund\DiasporaApportionmentService;
use App\Exceptions\DomainRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\SocialFund\StoreDiasporaApportionmentRequest;
use App\Http\Resources\DiasporaApportionmentResource;
use App\Models\DiasporaApportionment;
use App\Models\DiasporaApportionmentItem;
use App\Support\Kwacha;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The diaspora split calculator and its checklist of transfers.
 *
 * The preview endpoint returns the service's own arithmetic rather than letting the
 * screen divide, so what the committee approves is exactly what will be written.
 */
class DiasporaApportionmentController extends Controller
{
    public function __construct(
        protected DiasporaApportionmentService $apportionments,
        protected SocialFundOverview $overview,
    ) {}

    public function index(Request $request, CurrentCycle $currentCycle): Response
    {
        $this->authorize('viewAny', DiasporaApportionment::class);

        $cycle = $currentCycle->get();

        return Inertia::render('app/fund/Apportionment', [
            'apportionments' => $cycle === null ? [] : DiasporaApportionmentResource::collection(
                DiasporaApportionment::query()->forCycle($cycle)
                    ->with('items.member', 'recordedBy', 'secondApprover')
                    ->latest('declared_on')->latest('id')->get(),
            ),
            'recipients' => $cycle === null ? [] : $this->apportionments->recipients($cycle)
                ->map(fn ($member): array => [
                    'member_id' => $member->id,
                    'member_number' => $member->member_number,
                    'full_name' => $member->full_name,
                ])->all(),
            'balance_ngwee' => $cycle === null ? 0 : $this->overview->for($cycle)['balance_ngwee'],
            'abilities' => [
                'create' => $request->user()->can('create', DiasporaApportionment::class),
            ],
        ]);
    }

    /** The live split preview behind the calculator, computed by the service. */
    public function preview(Request $request, CurrentCycle $currentCycle): JsonResponse
    {
        $this->authorize('viewAny', DiasporaApportionment::class);

        $validated = $request->validate(['total_ngwee' => ['required', 'integer', 'min:0']]);

        return response()->json(
            $this->apportionments->preview($currentCycle->getOrFail(), Kwacha::ofNgwee($validated['total_ngwee']))
        );
    }

    public function store(StoreDiasporaApportionmentRequest $request, CurrentCycle $currentCycle): RedirectResponse
    {
        $actor = $request->user()->member;

        if ($actor === null) {
            return back()->withErrors(['total_ngwee' => 'Your login is not linked to a member record.']);
        }

        try {
            $this->apportionments->create(
                $currentCycle->getOrFail(),
                Kwacha::ofNgwee($request->integer('total_ngwee')),
                $actor->load('user'),
                $request->approver(),
                $request->filled('declared_on') ? Carbon::parse($request->string('declared_on')->toString()) : null,
                $request->input('note'),
            );
        } catch (DomainRuleException $exception) {
            return back()->withErrors(['total_ngwee' => $exception->getMessage()]);
        }

        return back()->with('success', 'The split is recorded. Tick each transfer off as it is sent.');
    }

    /** Ticking a transfer off, which is what debits the fund. */
    public function confirm(Request $request, DiasporaApportionmentItem $item): RedirectResponse
    {
        $this->authorize('confirmTransfer', $item);

        $validated = $request->validate([
            'paid_on' => ['nullable', 'date'],
            'reference' => ['nullable', 'string', 'max:120'],
        ]);

        $actor = $request->user()->member;

        if ($actor === null) {
            return back()->withErrors(['reference' => 'Your login is not linked to a member record.']);
        }

        try {
            $this->apportionments->confirmTransfer(
                $item->load('apportionment.cycle', 'member'),
                $actor,
                isset($validated['paid_on']) ? Carbon::parse($validated['paid_on']) : null,
                $validated['reference'] ?? null,
            );
        } catch (DomainRuleException $exception) {
            return back()->withErrors(['reference' => $exception->getMessage()]);
        }

        return back()->with('success', 'Transfer confirmed and the fund debited.');
    }
}
