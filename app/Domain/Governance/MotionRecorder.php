<?php

namespace App\Domain\Governance;

use App\Enums\MemberStatus;
use App\Enums\MotionType;
use App\Enums\ThresholdBasis;
use App\Exceptions\DomainRuleException;
use App\Exceptions\MotionAlreadyDecidedException;
use App\Exceptions\QuorumNotMetException;
use App\Models\Amendment;
use App\Models\Meeting;
use App\Models\Member;
use App\Models\Motion;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Putting a motion, and writing down what the room did with it.
 *
 * Two rules meet here and are deliberately different. Removing an officer needs 60%
 * of the WHOLE active membership, so a thin meeting cannot unseat somebody and
 * staying away is not a vote against. Amending the constitution needs 60% of those
 * PRESENT, so the group can still change its own rules without a full house. Which
 * base applies is fixed by the motion's type, never chosen by whoever is recording.
 *
 * Deciding is one-way. The tally, the base it was measured against and the number of
 * votes that base required are all written together and never touched again, so the
 * minutes stay readable years later even as the membership moves underneath them.
 */
class MotionRecorder
{
    public function __construct(
        protected VotingThreshold $threshold,
        protected MeetingRegister $register,
        protected AmendmentWindow $window,
        protected CommitteeTermService $terms,
    ) {}

    /**
     * Puts a motion to the group.
     *
     * A no-confidence motion may be raised without a meeting — the constitution allows
     * it so an officer cannot bury one by never calling the group together. Everything
     * else belongs to a meeting.
     *
     * @param  array{
     *     section_reference: string,
     *     current_text: string,
     *     proposed_text: string,
     *     effective_date: string,
     * }|null  $amendment
     */
    public function propose(
        MotionType $type,
        string $subject,
        Member $proposedBy,
        ?Meeting $meeting = null,
        ?Member $target = null,
        ?array $amendment = null,
        ?CarbonInterface $at = null,
    ): Motion {
        $cycle = $proposedBy->cycle;

        if ($meeting === null && $type !== MotionType::NoConfidence) {
            throw DomainRuleException::make('Only a no-confidence motion may be raised outside a meeting.');
        }

        if ($type === MotionType::NoConfidence && $target === null) {
            throw DomainRuleException::make('A no-confidence motion has to name the officer it concerns.');
        }

        if ($type === MotionType::Amendment) {
            $this->window->assertOpen($cycle, $at);

            if ($amendment === null) {
                throw DomainRuleException::make('An amendment motion needs the section and the wording it would replace.');
            }
        }

        return DB::transaction(function () use ($type, $subject, $proposedBy, $meeting, $target, $amendment, $cycle): Motion {
            $motion = Motion::create([
                'cycle_id' => $cycle->id,
                'meeting_id' => $meeting?->id,
                'type' => $type,
                'subject' => $subject,
                'target_member_id' => $target?->id,
                'proposed_by_member_id' => $proposedBy->id,
                'threshold_basis' => $type->thresholdBasis(),
            ]);

            if ($amendment !== null) {
                Amendment::create([
                    'cycle_id' => $cycle->id,
                    'motion_id' => $motion->id,
                    'section_reference' => $amendment['section_reference'],
                    'current_text' => $amendment['current_text'],
                    'proposed_text' => $amendment['proposed_text'],
                    'effective_date' => $amendment['effective_date'],
                ]);
            }

            $this->log($motion, "{$proposedBy->full_name} proposed: {$subject}");

            return $motion->refresh();
        });
    }

    /**
     * Records the show of hands and settles the motion.
     *
     * Where it carries and it was a no-confidence motion, the officer's terms end as
     * `Removed` inside the same transaction — the removal is a consequence of the
     * tally, not a second decision somebody has to remember to make.
     */
    public function decide(
        Motion $motion,
        int $votesFor,
        int $votesAgainst,
        int $abstentions = 0,
        ?CarbonInterface $at = null,
    ): Motion {
        $at = $at ?? now();

        if ($motion->isDecided()) {
            throw new MotionAlreadyDecidedException(
                'That motion has already been decided. Minutes are corrected by a fresh motion, never by editing this one.',
            );
        }

        foreach (['for' => $votesFor, 'against' => $votesAgainst, 'abstentions' => $abstentions] as $label => $tally) {
            if ($tally < 0) {
                throw DomainRuleException::make("The {$label} tally cannot be negative.");
            }
        }

        $this->assertQuorum($motion);

        $base = $this->baseFor($motion);
        $inTheRoom = $this->roomSize($motion);

        if ($votesFor + $votesAgainst + $abstentions > $inTheRoom) {
            throw DomainRuleException::make(sprintf(
                'That is %d hands from %d members present.',
                $votesFor + $votesAgainst + $abstentions,
                $inTheRoom,
            ));
        }

        $needed = $this->threshold->needed($base);
        $passed = $this->threshold->isMet($votesFor, $base);

        return DB::transaction(function () use ($motion, $votesFor, $votesAgainst, $abstentions, $base, $needed, $passed, $at): Motion {
            $motion->forceFill([
                'votes_for' => $votesFor,
                'votes_against' => $votesAgainst,
                'abstentions' => $abstentions,
                'eligible_count' => $base,
                'votes_needed' => $needed,
                'passed' => $passed,
                'decided_at' => $at,
            ])->save();

            if ($passed && $motion->type === MotionType::NoConfidence && $motion->target !== null) {
                $this->terms->removeFromOffice($motion->target, $at->copy()->startOfDay());
            }

            $this->log($motion, sprintf(
                'Motion %s: %s (%d for, %d against, %d abstained — %s)',
                $passed ? 'carried' : 'failed',
                $motion->subject,
                $votesFor,
                $votesAgainst,
                $abstentions,
                $motion->thresholdExplanation(),
            ));

            return $motion->refresh();
        });
    }

    /**
     * What deciding this motion would need right now, before anybody votes.
     *
     * The motions panel shows this beside the tally boxes so the committee sees the
     * bar — and which base it is measured against — before recording anything.
     *
     * @return array{
     *     basis: string,
     *     basis_label: string,
     *     base: int,
     *     needed: int,
     *     explanation: string,
     *     quorum_met: bool,
     *     can_decide: bool,
     *     blocked_reason: string|null,
     * }
     */
    public function requirement(Motion $motion): array
    {
        $base = $this->baseFor($motion);
        $quorumMet = $motion->meeting === null || $this->register->hasQuorum($motion->meeting);

        return [
            'basis' => $motion->threshold_basis->value,
            'basis_label' => $motion->threshold_basis->label(),
            'base' => $base,
            'needed' => $this->threshold->needed($base),
            'explanation' => $this->threshold->explain(
                $base,
                $motion->threshold_basis === ThresholdBasis::TotalMembers
                    ? 'total active members'
                    : 'members present',
            ),
            'quorum_met' => $quorumMet,
            'can_decide' => ! $motion->isDecided() && $quorumMet && $base > 0,
            'blocked_reason' => match (true) {
                $motion->isDecided() => 'This motion has already been decided.',
                ! $quorumMet => 'The meeting has not reached quorum, so no motion can be decided.',
                $base <= 0 => 'Nobody is present to vote.',
                default => null,
            },
        ];
    }

    /**
     * The quorum gate.
     *
     * A motion raised outside a meeting has no room to be quorate, and its base is the
     * whole membership anyway, so it is not gated — that is the point of allowing it.
     */
    protected function assertQuorum(Motion $motion): void
    {
        if ($motion->meeting === null) {
            return;
        }

        if ($this->register->hasQuorum($motion->meeting)) {
            return;
        }

        $quorum = $this->register->quorum($motion->meeting);

        throw new QuorumNotMetException(sprintf(
            'The meeting has %d of the %d members needed for quorum, so no motion can be decided.',
            $quorum['present'],
            $quorum['needed'],
        ));
    }

    /** The population this motion's 60% is taken against. */
    protected function baseFor(Motion $motion): int
    {
        return match ($motion->threshold_basis) {
            ThresholdBasis::TotalMembers => $this->activeCount($motion),
            ThresholdBasis::MembersPresent => $motion->meeting === null
                ? 0
                : $this->register->presentCount($motion->meeting),
        };
    }

    /** How many hands there are to raise, whichever base the motion uses. */
    protected function roomSize(Motion $motion): int
    {
        return $motion->meeting === null
            ? $this->activeCount($motion)
            : $this->register->presentCount($motion->meeting);
    }

    protected function activeCount(Motion $motion): int
    {
        return Member::query()
            ->forCycle($motion->cycle_id)
            ->where('status', MemberStatus::Active)
            ->count();
    }

    protected function log(Motion $motion, string $message): void
    {
        activity('governance')
            ->performedOn($motion)
            ->withProperties([
                'motion_id' => $motion->id,
                'type' => $motion->type->value,
                'meeting_id' => $motion->meeting_id,
                'target_member_id' => $motion->target_member_id,
                'threshold_basis' => $motion->threshold_basis->value,
                'eligible_count' => $motion->eligible_count,
                'votes_needed' => $motion->votes_needed,
                'passed' => $motion->passed,
            ])
            ->event('governance.motion')
            ->log($message);
    }
}
