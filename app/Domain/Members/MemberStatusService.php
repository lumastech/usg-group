<?php

namespace App\Domain\Members;

use App\Enums\ExpulsionGround;
use App\Enums\MemberStatus;
use App\Exceptions\InvalidStatusTransitionException;
use App\Models\Member;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The only way a member's status ever changes.
 *
 * Status drives what a member is paid at share-out — an expelled member forfeits
 * their interest, a deceased member's estate does not — so every change is guarded
 * here, recorded with its reason, and written to the activity log for the timeline
 * on the member's profile.
 */
class MemberStatusService
{
    /**
     * Move a member to a new status.
     *
     * @param  array{
     *     reason?: string|null,
     *     expulsion_ground?: ExpulsionGround|string|null,
     *     date_of_death?: CarbonInterface|string|null,
     *     effective_on?: CarbonInterface|string|null,
     * }  $context
     *
     * @throws InvalidStatusTransitionException
     */
    public function transition(Member $member, MemberStatus $to, array $context = [], ?User $by = null): Member
    {
        $from = $member->status;

        $this->guard($from, $to, $context);

        return DB::transaction(function () use ($member, $from, $to, $context, $by): Member {
            $member->forceFill([
                'status' => $to,
                'status_reason' => $context['reason'] ?? null,
                'status_effective_on' => $this->effectiveOn($to, $context),
                'status_changed_at' => Carbon::now(),
                'expulsion_ground' => $to->requiresExpulsionGround() ? $this->ground($context) : null,
                'date_of_death' => $to->requiresDateOfDeath() ? $this->dateOfDeath($context) : null,
            ])->save();

            activity()
                ->performedOn($member)
                ->causedBy($by)
                ->withProperties([
                    'from' => $from->value,
                    'to' => $to->value,
                    'reason' => $context['reason'] ?? null,
                    'expulsion_ground' => $member->expulsion_ground?->value,
                    'date_of_death' => $member->date_of_death?->toDateString(),
                    'effective_on' => $member->status_effective_on?->toDateString(),
                ])
                ->event('status_changed')
                ->log("Status changed from {$from->label()} to {$to->label()}");

            return $member;
        });
    }

    /**
     * @param  array<string, mixed>  $context
     *
     * @throws InvalidStatusTransitionException
     */
    protected function guard(MemberStatus $from, MemberStatus $to, array $context): void
    {
        if ($from === $to) {
            throw new InvalidStatusTransitionException("The member is already {$to->label()}.");
        }

        if (! $from->canTransitionTo($to)) {
            throw new InvalidStatusTransitionException(
                "A member who is {$from->label()} cannot be moved to {$to->label()}."
            );
        }

        if ($to->requiresExpulsionGround() && blank($context['expulsion_ground'] ?? null)) {
            throw new InvalidStatusTransitionException('An expulsion must record the ground for it.');
        }

        if ($to->requiresDateOfDeath() && blank($context['date_of_death'] ?? null)) {
            throw new InvalidStatusTransitionException('Recording a death requires the date of death.');
        }
    }

    /** @param  array<string, mixed>  $context */
    protected function ground(array $context): ExpulsionGround
    {
        $ground = $context['expulsion_ground'];

        return $ground instanceof ExpulsionGround ? $ground : ExpulsionGround::from((string) $ground);
    }

    /** @param  array<string, mixed>  $context */
    protected function dateOfDeath(array $context): Carbon
    {
        return Carbon::parse($context['date_of_death']);
    }

    /**
     * When the change takes effect: the date given, else a death's own date, else today.
     *
     * @param  array<string, mixed>  $context
     */
    protected function effectiveOn(MemberStatus $to, array $context): Carbon
    {
        if (filled($context['effective_on'] ?? null)) {
            return Carbon::parse($context['effective_on']);
        }

        if ($to->requiresDateOfDeath() && filled($context['date_of_death'] ?? null)) {
            return $this->dateOfDeath($context);
        }

        return Carbon::today();
    }
}
