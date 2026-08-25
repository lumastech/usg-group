<?php

use App\Domain\Cycles\CurrentCycle;
use App\Domain\Cycles\CycleMonthPlanner;
use App\Enums\MemberRole;
use App\Enums\MemberStatus;
use App\Models\Cycle;
use App\Models\Member;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Laravel\Fortify\Features;

/**
 * Signing up while `unity.open_registration` is on.
 *
 * The setting exists so a tester can start from nothing and reach share-out. What it
 * must never do is hand out a membership the constitution would have refused, which is
 * what the closed-window case below is guarding.
 */
beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());

    Carbon::setTestNow('2026-01-15 09:00:00');

    $this->seed(RoleSeeder::class);

    $this->cycle = Cycle::factory()->create([
        'starts_on' => Carbon::parse('2025-12-01'),
        'ends_on' => Carbon::parse('2026-11-30'),
        'registration_closes_after_month' => 3,
    ]);

    app(CycleMonthPlanner::class)->plan($this->cycle);
    app(CurrentCycle::class)->set($this->cycle);
});

function signUp(array $overrides = []): TestResponse
{
    return test()->post(route('register.store'), $overrides + [
        'name' => 'Chanda Mulenga',
        'email' => 'chanda@example.test',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);
}

it('attaches no membership while the setting is off', function () {
    config()->set('unity.open_registration', false);

    signUp();

    $user = User::where('email', 'chanda@example.test')->firstOrFail();

    expect($user->member)->toBeNull()
        ->and($user->hasRole(MemberRole::Member->value))->toBeFalse();
});

it('registers the signer-up into the cycle while the setting is on', function () {
    config()->set('unity.open_registration', true);

    signUp()->assertRedirect();

    $user = User::where('email', 'chanda@example.test')->firstOrFail();
    $member = Member::query()->where('user_id', $user->id)->firstOrFail();

    expect($member->cycle_id)->toBe($this->cycle->id)
        ->and($member->full_name)->toBe('Chanda Mulenga')
        ->and($member->status)->toBe(MemberStatus::Active)
        ->and($member->member_number)->toBe(1)
        ->and($member->joining_month_sequence)->toBe(2)
        /* Unpaid on purpose: paying it is the first step of the walkthrough. */
        ->and($member->joining_fee_paid)->toBeFalse()
        ->and($user->hasRole(MemberRole::Member->value))->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull();
});

it('numbers each new member after the ones already in the register', function () {
    config()->set('unity.open_registration', true);

    Member::factory()->for($this->cycle)->create(['member_number' => 7]);

    signUp();

    expect(Member::query()->where('full_name', 'Chanda Mulenga')->firstOrFail()->member_number)->toBe(8);
});

it('refuses once the cycle has closed registration, and leaves no orphan login', function () {
    config()->set('unity.open_registration', true);

    /* Month 9 — long past the third, which is where the constitution shuts the door. */
    Carbon::setTestNow('2026-08-22 10:00:00');

    signUp()->assertSessionHasErrors('email');

    expect(User::where('email', 'chanda@example.test')->exists())->toBeFalse()
        ->and(Member::query()->where('full_name', 'Chanda Mulenga')->exists())->toBeFalse();
});

it('refuses when there is no cycle to join', function () {
    config()->set('unity.open_registration', true);

    $this->cycle->delete();
    app(CurrentCycle::class)->set(null);

    signUp()->assertSessionHasErrors('email');

    expect(User::where('email', 'chanda@example.test')->exists())->toBeFalse();
});
