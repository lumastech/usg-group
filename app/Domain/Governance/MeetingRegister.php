<?php

namespace App\Domain\Governance;

use App\Enums\MemberStatus;
use App\Models\Meeting;
use App\Models\Member;
use Illuminate\Support\Collection;

/**
 * The attendance register, and the quorum read off it.
 *
 * Quorum is deliberately not stored. The secretary works the register on a phone as
 * people walk in, and the count on screen has to be the truth at that moment. What
 * does get frozen is the base a motion was decided against — see MotionRecorder — so
 * the live count never rewrites a decision already taken.
 *
 * Only Active members count on either side of the fraction. Somebody who has left or
 * died is neither expected in the room nor part of the membership quorum is asked of.
 */
class MeetingRegister
{
    public function __construct(protected VotingThreshold $threshold) {}

    /** Marks a member present or absent. Idempotent — the register is a set. */
    public function mark(Meeting $meeting, Member $member, bool $present): void
    {
        if ($present) {
            $meeting->attendees()->syncWithoutDetaching([$member->id]);

            return;
        }

        $meeting->attendees()->detach($member->id);
    }

    /** Flips one member's mark, which is what a tap on the register does. */
    public function toggle(Meeting $meeting, Member $member): bool
    {
        $present = ! $this->isPresent($meeting, $member);

        $this->mark($meeting, $member, $present);

        return $present;
    }

    public function isPresent(Meeting $meeting, Member $member): bool
    {
        return $meeting->attendees()->whereKey($member->id)->exists();
    }

    /** Everybody the register expects, in member-number order. */
    public function roll(Meeting $meeting): Collection
    {
        return Member::query()
            ->forCycle($meeting->cycle_id)
            ->active()
            ->orderBy('member_number')
            ->get();
    }

    /** The membership quorum is measured against. */
    public function activeCount(Meeting $meeting): int
    {
        return Member::query()
            ->forCycle($meeting->cycle_id)
            ->active()
            ->count();
    }

    public function presentCount(Meeting $meeting): int
    {
        return $meeting->attendees()
            ->where('members.status', MemberStatus::Active)
            ->count();
    }

    public function quorumNeeded(Meeting $meeting): int
    {
        return $this->threshold->needed($this->activeCount($meeting));
    }

    public function hasQuorum(Meeting $meeting): bool
    {
        return $this->threshold->isMet($this->presentCount($meeting), $this->activeCount($meeting));
    }

    /**
     * Everything the quorum ring needs, in one read.
     *
     * @return array{
     *     present: int,
     *     active: int,
     *     needed: int,
     *     met: bool,
     *     shortfall: int,
     *     explanation: string,
     * }
     */
    public function quorum(Meeting $meeting): array
    {
        $present = $this->presentCount($meeting);
        $active = $this->activeCount($meeting);
        $needed = $this->threshold->needed($active);

        return [
            'present' => $present,
            'active' => $active,
            'needed' => $needed,
            'met' => $present >= $needed && $active > 0,
            'shortfall' => max(0, $needed - $present),
            'explanation' => "needs {$needed} of {$active} active members present",
        ];
    }
}
