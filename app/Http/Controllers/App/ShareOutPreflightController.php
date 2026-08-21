<?php

namespace App\Http\Controllers\App;

use App\Domain\Cycles\CurrentCycle;
use App\Domain\ShareOut\CycleCloser;
use App\Domain\ShareOut\ShareOutPreflight;
use App\Enums\CycleStatus;
use App\Exceptions\DomainRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\ShareOut\OpenShareOutRequest;
use App\Models\Payout;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The gate between a running cycle and share-out.
 *
 * The checklist is rendered here and re-run inside CycleCloser when the transition is
 * posted, because the screen goes stale the moment a repayment lands. What the page
 * shows is therefore advice; what the domain reads at the moment of signing is the
 * decision.
 */
class ShareOutPreflightController extends Controller
{
    public function __construct(
        protected ShareOutPreflight $preflight,
        protected CycleCloser $closer,
    ) {}

    public function index(Request $request, CurrentCycle $currentCycle): Response
    {
        $this->authorize('viewAny', Payout::class);

        $cycle = $currentCycle->get();

        if ($cycle === null) {
            return Inertia::render('app/shareout/Preflight', [
                'cycle' => null,
                'preflight' => null,
                'abilities' => ['beginClosing' => false, 'openShareOut' => false],
            ]);
        }

        $mayManage = $request->user()->can('cycles.manage');

        return Inertia::render('app/shareout/Preflight', [
            'cycle' => [
                'id' => $cycle->id,
                'name' => $cycle->name,
                'status' => $cycle->status,
                'status_label' => $cycle->status->label(),
                'is_sharing_out' => $cycle->status->isSharingOut(),
            ],
            'preflight' => $this->preflight->payload($cycle),
            'abilities' => [
                'beginClosing' => $mayManage && $cycle->status === CycleStatus::Active,
                'openShareOut' => $mayManage && $cycle->status === CycleStatus::Closing,
            ],
        ]);
    }

    /** Active → Closing. Lending stops and the checklist begins. */
    public function close(Request $request, CurrentCycle $currentCycle): RedirectResponse
    {
        abort_unless($request->user()->can('cycles.manage'), 403);

        $actor = $request->user()->member;

        if ($actor === null) {
            return back()->withErrors(['status' => 'Your login is not linked to a member record.']);
        }

        try {
            $cycle = $this->closer->beginClosing($currentCycle->getOrFail(), $actor->load('user'));
        } catch (DomainRuleException $exception) {
            return back()->withErrors(['status' => $exception->getMessage()]);
        }

        return back()->with('success', "The {$cycle->name} cycle is closed to new lending. Work the checklist down to zero.");
    }

    /** Closing → ShareOut, clean or overridden. */
    public function store(OpenShareOutRequest $request, CurrentCycle $currentCycle): RedirectResponse
    {
        $actor = $request->user()->member;

        if ($actor === null) {
            return back()->withErrors(['override_note' => 'Your login is not linked to a member record.']);
        }

        try {
            $cycle = $this->closer->openShareOut(
                $currentCycle->getOrFail(),
                $actor->load('user'),
                $request->approver()?->load('user'),
                $request->input('override_note'),
            );
        } catch (DomainRuleException $exception) {
            return back()->withErrors(['override_note' => $exception->getMessage()]);
        }

        return redirect()
            ->route('app.shareout.index')
            ->with('success', "The {$cycle->name} cycle is now sharing out. Members may be settled.");
    }
}
