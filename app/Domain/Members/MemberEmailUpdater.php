<?php

namespace App\Domain\Members;

use App\Exceptions\DomainRuleException;
use App\Models\Member;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Corrects the address on a member's portal login.
 *
 * The address lives on the login rather than the member record, so this is the one
 * way the committee changes where a member's mail goes without unlinking them. It
 * is also how an account is taken over — the new address receives every password
 * reset from then on — so the change is written to the member's activity log and a
 * member with no login is refused rather than quietly given one: attaching a login
 * is MemberInviter's job, and it sends an invitation the member has to act on.
 */
class MemberEmailUpdater
{
    /**
     * Point the member's login at a new address, or return it unchanged.
     *
     * @throws DomainRuleException
     */
    public function update(Member $member, string $email, ?User $by = null): User
    {
        $email = Str::lower(trim($email));
        $user = $member->user;

        if ($user === null) {
            throw new DomainRuleException(
                'This member has no portal login yet. Invite them from their profile to give them one.'
            );
        }

        if ($user->email === $email) {
            return $user;
        }

        $this->guardAddressIsFree($user, $email);

        return DB::transaction(function () use ($member, $user, $email, $by): User {
            $previous = $user->email;

            $user->forceFill(['email' => $email, 'email_verified_at' => null])->save();

            activity()
                ->performedOn($member)
                ->causedBy($by)
                ->withProperties(['from' => $previous, 'to' => $email])
                ->event('email_changed')
                ->log("Login email changed from {$previous} to {$email}");

            return $user;
        });
    }

    /** Two logins cannot share an address, and merging accounts is not a correction. */
    protected function guardAddressIsFree(User $user, string $email): void
    {
        $taken = User::query()
            ->where('email', $email)
            ->whereKeyNot($user->id)
            ->exists();

        if ($taken) {
            throw new DomainRuleException('Another login already uses that email address.');
        }
    }
}
