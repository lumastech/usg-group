<?php

use App\Domain\Members\MemberStatusService;
use App\Enums\ExpulsionGround;
use App\Enums\MemberStatus;
use App\Exceptions\InvalidStatusTransitionException;
use App\Models\Cycle;
use App\Models\Member;
use App\Models\User;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity;

/**
 * Status decides what a member is paid at share-out, so every guard here is a
 * money rule wearing a different hat.
 */
beforeEach(function () {
    $this->statuses = app(MemberStatusService::class);
    $this->cycle = Cycle::factory()->create();
    $this->member = Member::factory()->for($this->cycle)->create();
});

it('records a member leaving early', function () {
    $member = $this->statuses->transition($this->member, MemberStatus::LeftEarly, [
        'reason' => 'Relocated to Ndola.',
    ]);

    expect($member->status)->toBe(MemberStatus::LeftEarly)
        ->and($member->status_reason)->toBe('Relocated to Ndola.')
        ->and($member->status_changed_at)->not->toBeNull()
        ->and($member->status_effective_on->toDateString())->toBe(Carbon::today()->toDateString());
});

it('refuses an expulsion with no ground', function () {
    $this->statuses->transition($this->member, MemberStatus::Expelled);
})->throws(InvalidStatusTransitionException::class, 'must record the ground');

it('refuses a death with no date', function () {
    $this->statuses->transition($this->member, MemberStatus::Deceased);
})->throws(InvalidStatusTransitionException::class, 'requires the date of death');

it('records an expulsion against its ground', function () {
    $member = $this->statuses->transition($this->member, MemberStatus::Expelled, [
        'expulsion_ground' => ExpulsionGround::LoanMisconduct->value,
    ]);

    expect($member->expulsion_ground)->toBe(ExpulsionGround::LoanMisconduct)
        ->and($member->date_of_death)->toBeNull();
});

it('dates a death from the date of death rather than today', function () {
    $member = $this->statuses->transition($this->member, MemberStatus::Deceased, [
        'date_of_death' => '2026-05-04',
    ]);

    expect($member->date_of_death->toDateString())->toBe('2026-05-04')
        ->and($member->status_effective_on->toDateString())->toBe('2026-05-04');
});

it('refuses to move a member to the status they already hold', function () {
    $this->statuses->transition($this->member, MemberStatus::Active);
})->throws(InvalidStatusTransitionException::class, 'already Active');

it('treats death as final', function () {
    $this->statuses->transition($this->member, MemberStatus::Deceased, ['date_of_death' => '2026-05-04']);

    $this->statuses->transition($this->member, MemberStatus::Active);
})->throws(InvalidStatusTransitionException::class, 'cannot be moved to Active');

it('refuses to expel a member who has already left', function () {
    $this->statuses->transition($this->member, MemberStatus::LeftEarly);

    $this->statuses->transition($this->member, MemberStatus::Expelled, [
        'expulsion_ground' => ExpulsionGround::Theft->value,
    ]);
})->throws(InvalidStatusTransitionException::class, 'cannot be moved to Expelled');

it('reinstates a member recorded as expelled by mistake', function () {
    $this->statuses->transition($this->member, MemberStatus::Expelled, [
        'expulsion_ground' => ExpulsionGround::Dishonesty->value,
    ]);

    $member = $this->statuses->transition($this->member, MemberStatus::Active, ['reason' => 'Wrong member.']);

    expect($member->status)->toBe(MemberStatus::Active)
        ->and($member->expulsion_ground)->toBeNull();
});

it('logs every change with who made it and what it was', function () {
    $treasurer = User::factory()->create();

    $this->statuses->transition($this->member, MemberStatus::Expelled, [
        'expulsion_ground' => ExpulsionGround::Theft->value,
        'reason' => 'Took group funds.',
    ], $treasurer);

    $activity = Activity::query()->where('event', 'status_changed')->latest('id')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->causer_id)->toBe($treasurer->id)
        ->and($activity->properties['from'])->toBe(MemberStatus::Active->value)
        ->and($activity->properties['to'])->toBe(MemberStatus::Expelled->value)
        ->and($activity->properties['expulsion_ground'])->toBe(ExpulsionGround::Theft->value);
});
