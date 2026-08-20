<?php

namespace App\Http\Controllers\App;

use App\Domain\Cycles\CurrentCycle;
use App\Domain\Governance\AmendmentWindow;
use App\Domain\Governance\MotionRecorder;
use App\Enums\MotionType;
use App\Exceptions\DomainRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Governance\StoreAmendmentRequest;
use App\Http\Resources\AmendmentResource;
use App\Models\Amendment;
use App\Models\Meeting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The amendment log, and the six-month gate in front of the proposal form.
 *
 * The countdown is served with the page rather than worked out in the browser, so what
 * blocks the form is the same calculation that would refuse the submission.
 */
class AmendmentController extends Controller
{
    public function __construct(
        protected AmendmentWindow $window,
        protected MotionRecorder $motions,
    ) {}

    public function index(Request $request, CurrentCycle $currentCycle): Response
    {
        $this->authorize('viewAny', Amendment::class);

        $cycle = $currentCycle->get();

        if ($cycle === null) {
            return Inertia::render('app/governance/Amendments', [
                'cycle' => null,
                'amendments' => [],
                'window' => null,
                'meetings' => [],
                'abilities' => ['record' => false],
            ]);
        }

        $amendments = Amendment::query()
            ->forCycle($cycle->id)
            ->with('motion')
            ->orderByDesc('id')
            ->get();

        return Inertia::render('app/governance/Amendments', [
            'cycle' => ['id' => $cycle->id, 'name' => $cycle->name],
            'amendments' => AmendmentResource::collection($amendments),
            'window' => $this->window->payload($cycle),
            'meetings' => Meeting::query()
                ->forCycle($cycle->id)
                ->orderByDesc('meeting_date')
                ->get()
                ->map(fn (Meeting $meeting): array => [
                    'id' => $meeting->id,
                    'label' => $meeting->label(),
                ])->all(),
            'abilities' => [
                'record' => $request->user()->can('create', Amendment::class),
            ],
        ]);
    }

    /**
     * Proposes a change, as an amendment motion with the wording attached.
     *
     * The spacing rule is checked by the domain, not here: a form that opened while the
     * window was closing must still be refused.
     */
    public function store(StoreAmendmentRequest $request): RedirectResponse
    {
        $proposer = $request->user()->member;

        if ($proposer === null) {
            return back()->withErrors(['amendment' => 'Your login is not linked to a member record.']);
        }

        try {
            $this->motions->propose(
                type: MotionType::Amendment,
                subject: $request->string('subject')->toString(),
                proposedBy: $proposer,
                meeting: Meeting::findOrFail($request->integer('meeting_id')),
                amendment: $request->amendment(),
            );
        } catch (DomainRuleException $exception) {
            return back()->withErrors(['amendment' => $exception->getMessage()]);
        }

        return back()->with('success', 'The amendment is on the table for the meeting.');
    }
}
