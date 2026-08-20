<?php

namespace App\Policies;

use App\Models\Motion;
use App\Models\User;

/**
 * Who may put a motion and record how it went.
 *
 * Deciding is additionally gated on the room: a motion in a meeting that never reached
 * quorum cannot be settled at all, so the screen disables the action and explains
 * itself rather than letting the tally be typed and refused. The domain refuses it too
 * — this only stops the button being offered.
 */
class MotionPolicy
{
    public function __construct(protected MeetingPolicy $meetings) {}

    public function viewAny(User $user): bool
    {
        return $this->meetings->viewAny($user);
    }

    public function view(User $user, Motion $motion): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->meetings->create($user);
    }

    /** Recording the show of hands. Once only — minutes are corrected by a new motion. */
    public function decide(User $user, Motion $motion): bool
    {
        return $this->create($user) && ! $motion->isDecided();
    }
}
