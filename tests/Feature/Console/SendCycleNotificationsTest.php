<?php

use App\Domain\Cycles\CurrentCycle;
use App\Domain\Cycles\CycleMonthPlanner;
use App\Enums\CycleStatus;
use App\Enums\MemberRole;
use App\Models\Cycle;
use App\Models\NotificationDispatch;
use App\Notifications\DeclarationWindowOpened;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->cycle = Cycle::factory()->create();
    app(CycleMonthPlanner::class)->plan($this->cycle);
    app(CurrentCycle::class)->set($this->cycle);

    $this->member = memberWithRole($this->cycle, MemberRole::Member);
});

it('sends the day it is run for', function () {
    Notification::fake();

    $this->travelTo(Carbon::parse('2026-01-01 08:00'));

    $this->artisan('unity:notify')->assertSuccessful();

    Notification::assertSentTo($this->member, DeclarationWindowOpened::class);
});

it('can be pointed at another date', function () {
    Notification::fake();

    $this->artisan('unity:notify', ['--date' => '2026-01-01'])->assertSuccessful();

    Notification::assertSentTo($this->member, DeclarationWindowOpened::class);
});

it('sends nothing twice when the schedule runs again', function () {
    Notification::fake();

    $this->artisan('unity:notify', ['--date' => '2026-01-01'])->assertSuccessful();
    $this->artisan('unity:notify', ['--date' => '2026-01-01'])->assertSuccessful();

    Notification::assertSentToTimes($this->member, DeclarationWindowOpened::class, 1);
});

it('leaves no trace when pretending, so the real run still fires', function () {
    $this->artisan('unity:notify', ['--date' => '2026-01-01', '--pretend' => true])
        ->expectsOutputToContain('declarations.open')
        ->assertSuccessful();

    expect(NotificationDispatch::query()->count())->toBe(0);

    Notification::fake();

    $this->artisan('unity:notify', ['--date' => '2026-01-01'])->assertSuccessful();

    Notification::assertSentTo($this->member, DeclarationWindowOpened::class);
});

it('says so rather than failing when the group has no active cycle', function () {
    app(CurrentCycle::class)->forget();
    $this->cycle->forceFill(['status' => CycleStatus::Closed])->save();

    $this->artisan('unity:notify')->assertSuccessful();
});
