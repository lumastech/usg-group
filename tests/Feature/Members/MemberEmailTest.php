<?php

use App\Domain\Members\MemberEmailUpdater;
use App\Enums\MemberRole;
use App\Exceptions\DomainRuleException;
use App\Models\Cycle;
use App\Models\Member;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity;

/**
 * Correcting the address on a member's portal login.
 *
 * The address lives on the login, not the member record, so the field is only
 * writable for a member who has been invited — and every change is written to the
 * timeline, because the new address receives their password resets from then on.
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->cycle = Cycle::factory()->create();

    $this->chair = User::factory()->create();
    $this->chair->assignRole(MemberRole::Chairperson->value);

    $this->travelTo(Carbon::parse('2026-06-01'));
});

/** @return array<string, mixed> */
function correctionPayload(Member $member, array $overrides = []): array
{
    return array_merge([
        'full_name' => $member->full_name,
        'nrc_number' => $member->nrc_number,
        'phone' => $member->phone,
        'physical_address' => $member->physical_address,
        'is_diaspora' => $member->is_diaspora,
        'joining_fee_paid' => $member->joining_fee_paid,
        'next_of_kin' => [],
    ], $overrides);
}

it('lets an office holder correct the email on a member with a login', function () {
    $user = User::factory()->create(['email' => 'old@example.com']);
    $member = Member::factory()->for($this->cycle)->create(['user_id' => $user->id]);

    $this->actingAs($this->chair)
        ->put(route('app.members.update', $member), correctionPayload($member, [
            'email' => 'New.Address@Example.com',
        ]))
        ->assertSessionHasNoErrors();

    expect($user->fresh()->email)->toBe('new.address@example.com');
});

it('records the change on the member timeline', function () {
    $user = User::factory()->create(['email' => 'old@example.com']);
    $member = Member::factory()->for($this->cycle)->create(['user_id' => $user->id]);

    app(MemberEmailUpdater::class)->update($member, 'new@example.com', $this->chair);

    $activity = Activity::query()
        ->where('subject_type', Member::class)
        ->where('subject_id', $member->id)
        ->where('event', 'email_changed')
        ->sole();

    expect($activity->properties['from'])->toBe('old@example.com')
        ->and($activity->properties['to'])->toBe('new@example.com')
        ->and($activity->causer_id)->toBe($this->chair->id);
});

it('refuses an address another login already holds', function () {
    User::factory()->create(['email' => 'taken@example.com']);
    $user = User::factory()->create(['email' => 'mine@example.com']);
    $member = Member::factory()->for($this->cycle)->create(['user_id' => $user->id]);

    $this->actingAs($this->chair)
        ->put(route('app.members.update', $member), correctionPayload($member, [
            'email' => 'taken@example.com',
        ]))
        ->assertSessionHasErrors('email');

    expect($user->fresh()->email)->toBe('mine@example.com');
});

it('rejects an email for a member who has no login yet', function () {
    $member = Member::factory()->for($this->cycle)->create(['user_id' => null]);

    $this->actingAs($this->chair)
        ->put(route('app.members.update', $member), correctionPayload($member, [
            'email' => 'nobody@example.com',
        ]))
        ->assertSessionHasErrors('email');

    expect(User::where('email', 'nobody@example.com')->exists())->toBeFalse();
});

it('saves the rest of the correction when the email is left as it was', function () {
    $user = User::factory()->create(['email' => 'same@example.com']);
    $member = Member::factory()->for($this->cycle)->create(['user_id' => $user->id]);

    $this->actingAs($this->chair)
        ->put(route('app.members.update', $member), correctionPayload($member, [
            'full_name' => 'Corrected Name',
            'email' => 'same@example.com',
        ]))
        ->assertSessionHasNoErrors();

    expect($member->fresh()->full_name)->toBe('Corrected Name')
        ->and(Activity::where('event', 'email_changed')->count())->toBe(0);
});

it('will not attach a login to a member who has none', function () {
    $member = Member::factory()->for($this->cycle)->create(['user_id' => null]);

    app(MemberEmailUpdater::class)->update($member, 'nobody@example.com');
})->throws(DomainRuleException::class, 'no portal login yet');
