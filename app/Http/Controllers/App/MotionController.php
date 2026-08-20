<?php

namespace App\Http\Controllers\App;

use App\Domain\Governance\MotionRecorder;
use App\Exceptions\DomainRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Governance\DecideMotionRequest;
use App\Http\Requests\Governance\StoreMotionRequest;
use App\Models\Meeting;
use App\Models\Member;
use App\Models\Motion;
use Illuminate\Http\RedirectResponse;

/**
 * Putting motions and recording how the room voted.
 *
 * Nothing about pass or fail comes from the request. The tally is posted; the base,
 * the number of votes it required and the outcome are all worked out server-side
 * against the motion's own threshold, which its type fixed when it was proposed.
 */
class MotionController extends Controller
{
    public function __construct(protected MotionRecorder $motions) {}

    /**
     * Puts a motion, in a meeting or — for no confidence alone — outside one.
     *
     * The out-of-meeting path exists so an officer cannot bury a motion about
     * themselves by never calling the group together.
     */
    public function store(StoreMotionRequest $request, ?Meeting $meeting = null): RedirectResponse
    {
        $proposer = $request->user()->member;

        if ($proposer === null) {
            return back()->withErrors(['motion' => 'Your login is not linked to a member record.']);
        }

        $targetId = $request->integer('target_member_id') ?: null;

        try {
            $this->motions->propose(
                type: $request->type(),
                subject: $request->string('subject')->toString(),
                proposedBy: $proposer,
                meeting: $meeting,
                target: $targetId === null ? null : Member::findOrFail($targetId),
            );
        } catch (DomainRuleException $exception) {
            return back()->withErrors(['motion' => $exception->getMessage()]);
        }

        return back()->with('success', 'The motion is on the table.');
    }

    /** Records the show of hands and settles the motion, once and for all. */
    public function decide(DecideMotionRequest $request, Motion $motion): RedirectResponse
    {
        try {
            $decided = $this->motions->decide(
                $motion,
                $request->integer('votes_for'),
                $request->integer('votes_against'),
                $request->integer('abstentions'),
            );
        } catch (DomainRuleException $exception) {
            return back()->withErrors(['motion' => $exception->getMessage()]);
        }

        return back()->with('success', sprintf(
            'The motion %s — %s.',
            $decided->hasPassed() ? 'carried' : 'failed',
            $decided->thresholdExplanation(),
        ));
    }
}
