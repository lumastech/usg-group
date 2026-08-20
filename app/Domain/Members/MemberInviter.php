<?php

namespace App\Domain\Members;

use App\Enums\MemberRole;
use App\Exceptions\DomainRuleException;
use App\Models\Member;
use App\Models\User;
use App\Notifications\MemberInvitation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Links a portal login to a member and invites them to activate it.
 *
 * Not every member logs in — some are on the phone to the treasurer instead — so a
 * member exists without a user until this runs. Whichever user ends up attached is
 * always granted the `member` role, so the /my portal works from the first sign-in.
 */
class MemberInviter
{
    /**
     * Attach a login for this email to the member and send the invitation.
     *
     * An existing account with that email is reused rather than duplicated, which
     * is how a committee member already holding an office login becomes a member.
     *
     * @throws DomainRuleException
     */
    public function invite(Member $member, string $email, ?string $name = null): User
    {
        $email = Str::lower(trim($email));

        $user = DB::transaction(function () use ($member, $email, $name): User {
            $user = User::where('email', $email)->first();

            if ($user === null) {
                $user = User::create([
                    'name' => $name ?? $member->full_name,
                    'email' => $email,
                    'password' => Str::password(32),
                ]);
            }

            $this->guardNotTakenByAnotherMember($member, $user);

            $member->forceFill(['user_id' => $user->id])->save();

            $user->assignRole(MemberRole::Member->value);

            return $user;
        });

        $user->notify(MemberInvitation::for($member->fresh()));

        return $user;
    }

    /** One login belongs to one member per cycle; sharing would cross their records. */
    protected function guardNotTakenByAnotherMember(Member $member, User $user): void
    {
        $taken = Member::query()
            ->forCycle($member->cycle_id)
            ->where('user_id', $user->id)
            ->whereKeyNot($member->id)
            ->exists();

        if ($taken) {
            throw new DomainRuleException('That login is already linked to another member in this cycle.');
        }
    }
}
