<?php

use App\Domain\Notifications\NotificationChannelManager;
use App\Enums\NotificationChannel;
use App\Models\Cycle;
use App\Models\Member;
use App\Models\User;

beforeEach(function () {
    $this->cycle = Cycle::factory()->create();
    $this->manager = app(NotificationChannelManager::class);
});

function memberReachableBy(Cycle $cycle, NotificationChannel $channel, ?string $phone, bool $withLogin): Member
{
    return Member::factory()->for($cycle)->create([
        'phone' => $phone,
        'notification_channel' => $channel,
        'user_id' => $withLogin ? User::factory()->create()->id : null,
    ])->load('user');
}

it('sends on the channel the member asked for', function () {
    $member = memberReachableBy($this->cycle, NotificationChannel::Sms, '0977000000', true);

    expect($this->manager->for($member))->toBe(['sms']);
});

it('sends on both when the member asked for both', function () {
    $member = memberReachableBy($this->cycle, NotificationChannel::Both, '0977000000', true);

    expect($this->manager->for($member))->toBe(['mail', 'sms']);
});

it('defaults a member with no stated preference to email', function () {
    $member = Member::factory()->for($this->cycle)->create(['user_id' => User::factory()])->load('user');

    expect($member->notification_channel)->toBe(NotificationChannel::Mail)
        ->and($this->manager->for($member))->toBe(['mail']);
});

it('falls back to email when the member asked for SMS but has no number', function () {
    $member = memberReachableBy($this->cycle, NotificationChannel::Sms, null, true);

    expect($this->manager->for($member))->toBe(['mail']);
});

it('falls back to SMS when the member has a number but no portal login', function () {
    $member = memberReachableBy($this->cycle, NotificationChannel::Mail, '0977000000', false);

    expect($this->manager->for($member))->toBe(['sms']);
});

it('drops the channel a member cannot be reached on when they asked for both', function () {
    $member = memberReachableBy($this->cycle, NotificationChannel::Both, null, true);

    expect($this->manager->for($member))->toBe(['mail']);
});

it('returns no channels for a member with neither an address nor a number', function () {
    $member = memberReachableBy($this->cycle, NotificationChannel::Both, null, false);

    expect($this->manager->for($member))->toBe([]);
});
