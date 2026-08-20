<?php

namespace App\Http\Controllers\App;

use App\Domain\Cycles\CurrentCycle;
use App\Domain\Governance\MeetingRegister;
use App\Http\Controllers\Controller;
use App\Http\Requests\Governance\StoreMeetingRequest;
use App\Http\Resources\MotionResource;
use App\Models\Meeting;
use App\Models\Member;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Meetings, and the register taken at each one.
 *
 * The show page is built for a phone held in the room: the whole roll, tapped one name
 * at a time, with the quorum count moving as the room fills. Everything it needs
 * arrives in the props, so marking somebody present is one small request and one
 * partial reload rather than a page rebuild.
 */
class MeetingController extends Controller
{
    public function __construct(protected MeetingRegister $register) {}

    public function index(Request $request, CurrentCycle $currentCycle): Response
    {
        $this->authorize('viewAny', Meeting::class);

        $cycle = $currentCycle->get();

        $meetings = $cycle === null ? collect() : Meeting::query()
            ->forCycle($cycle->id)
            ->withCount(['attendees', 'motions'])
            ->orderByDesc('meeting_date')
            ->get();

        return Inertia::render('app/governance/Meetings', [
            'cycle' => $cycle === null ? null : ['id' => $cycle->id, 'name' => $cycle->name],
            'meetings' => $meetings->map(fn (Meeting $meeting): array => [
                'id' => $meeting->id,
                'meeting_date' => $meeting->meeting_date->toDateString(),
                'type' => $meeting->type,
                'type_label' => $meeting->type->label(),
                'subject' => $meeting->subject,
                'attendees_count' => $meeting->attendees_count,
                'motions_count' => $meeting->motions_count,
                'quorum' => $this->register->quorum($meeting),
            ])->all(),
            'abilities' => [
                'record' => $request->user()->can('create', Meeting::class),
            ],
        ]);
    }

    public function show(Request $request, Meeting $meeting): Response
    {
        $this->authorize('view', $meeting);

        $present = $meeting->attendees()->pluck('members.id')->all();

        return Inertia::render('app/governance/MeetingShow', [
            'meeting' => [
                'id' => $meeting->id,
                'meeting_date' => $meeting->meeting_date->toDateString(),
                'type' => $meeting->type,
                'type_label' => $meeting->type->label(),
                'label' => $meeting->label(),
                'subject' => $meeting->subject,
                'notes' => $meeting->notes,
            ],
            'roll' => $this->register->roll($meeting)->map(fn (Member $member): array => [
                'id' => $member->id,
                'member_number' => $member->member_number,
                'full_name' => $member->full_name,
                'is_present' => in_array($member->id, $present, true),
            ])->values()->all(),
            'quorum' => $this->register->quorum($meeting),
            'motions' => MotionResource::collection(
                $meeting->motions()->with('target', 'proposedBy', 'amendment')->orderBy('id')->get(),
            ),
            'abilities' => [
                'record' => $request->user()->can('record', $meeting),
            ],
        ]);
    }

    public function store(StoreMeetingRequest $request, CurrentCycle $currentCycle): RedirectResponse
    {
        $cycle = $currentCycle->getOrFail();

        $meeting = Meeting::create([
            'cycle_id' => $cycle->id,
            'meeting_date' => $request->meetingDate(),
            'type' => $request->type(),
            'subject' => $request->string('subject')->toString() ?: null,
            'notes' => $request->string('notes')->toString() ?: null,
        ]);

        return to_route('app.governance.meetings.show', $meeting)
            ->with('success', "The register for the {$meeting->label()} is open.");
    }
}
