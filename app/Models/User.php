<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * The member record this login belongs to in the current cycle, if any.
     *
     * A login is not the same thing as a membership: a committee member may hold both,
     * an administrator may hold only a login, and most members exist in the register
     * long before they are ever invited to sign in. The relation is cycle-scoped, so it
     * resolves to this cycle's record for someone who has been a member for years.
     *
     * @return HasOne<Member, $this>
     */
    public function member(): HasOne
    {
        return $this->hasOne(Member::class);
    }

    /**
     * The member record acting here, refusing plainly when there is none.
     *
     * Every entry in the ledgers names the member who made it, so an action that moves
     * or records money cannot be carried out by a login that is not one — the
     * administrator most of all, who holds every permission and no membership by
     * design. Without this the actor arrives at the domain service as null and the
     * refusal reads as a type error, which tells the person at the screen nothing.
     *
     * @throws AuthorizationException
     */
    public function actingMember(): Member
    {
        $member = $this->member;

        if ($member === null) {
            throw new AuthorizationException(
                'This login is not linked to a member record in this cycle, and an entry in the '
                    .'group\'s ledgers has to name the member who made it. Ask an administrator to link it.'
            );
        }

        return $member;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }
}
