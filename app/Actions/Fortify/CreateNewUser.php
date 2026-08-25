<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Domain\Cycles\CurrentCycle;
use App\Domain\Members\MembershipRegistrar;
use App\Enums\MemberRole;
use App\Exceptions\DomainRuleException;
use App\Models\Cycle;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    public function __construct(
        protected CurrentCycle $currentCycle,
        protected MembershipRegistrar $registrar,
    ) {}

    /**
     * Validate and create a newly registered user.
     *
     * A login on its own is not a membership — see the note on `unity.open_registration`.
     * With that setting on, the sign-up also puts the person in the register, which is
     * what lets a tester start from nothing and reach share-out.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        if (! config('unity.open_registration')) {
            return User::create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => $input['password'],
            ]);
        }

        return $this->registerAsMember($input);
    }

    /**
     * Creates the login and the member record together, or neither.
     *
     * The membership goes through MembershipRegistrar rather than around it, so a
     * sign-up made while the cycle's registration window is shut is refused exactly as
     * a committee-entered one would be — the setting opens a door, it does not remove
     * the lock. Mail runs to the log in a test environment, so the address is taken as
     * verified here; there is no inbox for the tester to fetch the link from.
     *
     * @param  array<string, string>  $input
     */
    protected function registerAsMember(array $input): User
    {
        $cycle = $this->currentCycle->get();

        if (! $cycle instanceof Cycle) {
            throw ValidationException::withMessages([
                'email' => 'There is no active cycle to join yet.',
            ]);
        }

        try {
            return DB::transaction(function () use ($cycle, $input): User {
                $user = User::create([
                    'name' => $input['name'],
                    'email' => $input['email'],
                    'password' => $input['password'],
                ]);

                $user->forceFill(['email_verified_at' => Carbon::now()])->save();

                $this->registrar->register($cycle, [
                    'user_id' => $user->id,
                    'full_name' => $input['name'],
                ]);

                $user->assignRole(MemberRole::Member->value);

                return $user;
            });
        } catch (DomainRuleException $exception) {
            throw ValidationException::withMessages([
                'email' => $exception->getMessage(),
            ]);
        }
    }
}
