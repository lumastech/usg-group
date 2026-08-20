<?php

use App\Enums\MemberRole;
use App\Models\Cycle;
use App\Models\Member;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->cycle = Cycle::factory()->create();

    $this->user = User::factory()->create();
    $this->user->assignRole(MemberRole::Member->value);

    $this->member = Member::factory()->for($this->cycle)->withNextOfKin()->create([
        'user_id' => $this->user->id,
        'phone' => '0977000111',
    ]);
});

it('shows a member their own record with their next of kin', function () {
    $this->actingAs($this->user)
        ->get(route('my.profile'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('my/Profile')
            ->where('member.id', $this->member->id)
            ->has('member.next_of_kin', 1)
        );
});

it('renders an empty profile for a login with no member record', function () {
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->get(route('my.profile'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('member', null));
});

it('lets a member update their own phone and address, and logs it', function () {
    $this->actingAs($this->user)
        ->put(route('my.profile.update', $this->member), [
            'phone' => '0966123456',
            'physical_address' => 'Chalala Apex',
        ])
        ->assertSessionHasNoErrors();

    expect($this->member->fresh()->phone)->toBe('0966123456');

    $activity = Activity::query()->where('event', 'contact_details_updated')->latest('id')->first();

    expect($activity)->not->toBeNull()->and($activity->causer_id)->toBe($this->user->id);
});

it('never lets member A write to member B', function () {
    $other = Member::factory()->for($this->cycle)->create(['phone' => '0955999888']);

    $this->actingAs($this->user)
        ->put(route('my.profile.update', $other), ['phone' => '0900000000'])
        ->assertForbidden();

    expect($other->fresh()->phone)->toBe('0955999888');
});

it('does not let a member change anything but their contact details', function () {
    $this->actingAs($this->user)
        ->put(route('my.profile.update', $this->member), [
            'phone' => '0966123456',
            'full_name' => 'Self Promoted',
            'status' => 'expelled',
            'joining_fee_paid' => false,
        ])
        ->assertSessionHasNoErrors();

    $member = $this->member->fresh();

    expect($member->full_name)->toBe($this->member->full_name)
        ->and($member->status)->toBe($this->member->status)
        ->and($member->joining_fee_paid)->toBeTrue();
});
