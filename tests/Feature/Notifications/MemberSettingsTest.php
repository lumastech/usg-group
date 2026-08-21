<?php

use App\Enums\MemberRole;
use App\Enums\NotificationChannel;
use App\Models\Cycle;
use App\Models\Member;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Inertia\Testing\AssertableInertia;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->cycle = Cycle::factory()->create();
    $this->member = memberWithRole($this->cycle, MemberRole::Member, [
        'phone' => '0977000111',
    ]);
});

it('shows a member how the group will actually reach them', function () {
    $this->actingAs($this->member->user)
        ->get('/my/settings')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('my/Settings')
            ->where('member.notification_channel', 'mail')
            ->where('effective', ['mail'])
            ->has('channels', 3));
});

it('reports SMS as unreachable when there is no number on record', function () {
    $this->member->forceFill([
        'phone' => null,
        'notification_channel' => NotificationChannel::Sms,
    ])->save();

    $this->actingAs($this->member->user)
        ->get('/my/settings')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('effective', ['mail']));
});

it('saves the channel and the number together', function () {
    $this->actingAs($this->member->user)
        ->put('/my/settings/'.$this->member->id, [
            'notification_channel' => 'both',
            'phone' => '0966123123',
        ])
        ->assertRedirect();

    expect($this->member->refresh()->notification_channel)->toBe(NotificationChannel::Both)
        ->and($this->member->phone)->toBe('0966123123');
});

it('writes the change to the audit trail', function () {
    $this->actingAs($this->member->user)
        ->put('/my/settings/'.$this->member->id, ['notification_channel' => 'sms', 'phone' => '0966123123']);

    expect(Activity::query()->where('event', 'notification_preferences_updated')->exists())->toBeTrue();
});

it('rejects a channel that is not one of the three', function () {
    $this->actingAs($this->member->user)
        ->put('/my/settings/'.$this->member->id, ['notification_channel' => 'carrier-pigeon'])
        ->assertSessionHasErrors('notification_channel');
});

it('will not let one member change another member settings', function () {
    $other = Member::factory()->for($this->cycle)->create(['user_id' => User::factory()]);

    $this->actingAs($this->member->user)
        ->put('/my/settings/'.$other->id, ['notification_channel' => 'sms'])
        ->assertForbidden();
});
