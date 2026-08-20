<?php

use App\Domain\Cycles\CurrentCycle;
use App\Domain\Cycles\CycleMonthPlanner;
use App\Domain\Declarations\DeclarationService;
use App\Domain\Trading\TradingSessionService;
use App\Enums\MemberRole;
use App\Enums\TradingSessionStatus;
use App\Models\Cycle;
use App\Models\Member;
use App\Models\SavingsTransaction;
use App\Models\TradingSession;
use App\Models\User;
use App\Support\Kwacha;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;

/**
 * The trading console end to end: who may open it, who may mark it, and who may
 * conclude the month.
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);
    /* Trading week: the January window has closed and the day is running. */
    $this->travelTo(Carbon::parse('2026-01-07 09:00'));

    $this->cycle = Cycle::factory()->create();
    $this->months = app(CycleMonthPlanner::class)->plan($this->cycle);
    app(CurrentCycle::class)->set($this->cycle);

    $this->january = $this->months->firstWhere('sequence', 2);

    $this->saver = Member::factory()->for($this->cycle)->create(['full_name' => 'Bertha Phiri']);
    app(DeclarationService::class)->submit(
        $this->saver,
        $this->january,
        Kwacha::of(1_000),
        Kwacha::zero(),
        Kwacha::zero(),
        actor: $this->saver,
        at: Carbon::parse('2026-01-02 10:00'),
    );
});

/** Signs in as a user holding one role, with a member record of their own. */
function tradingAs(MemberRole $role): Member
{
    $user = User::factory()->create();
    $user->assignRole($role->value);

    $member = Member::factory()->for(test()->cycle)->create(['user_id' => $user->id]);

    test()->actingAs($user);

    return $member;
}

it('opens the session on the treasurer\'s first visit once the window has closed', function () {
    tradingAs(MemberRole::Treasurer);

    $this->get(route('app.trading.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('app/trading/Index')
            ->where('session.status', TradingSessionStatus::Open->value)
            ->where('abilities.operate', true)
            ->where('abilities.conclude', true)
            ->has('entries', 1));

    expect(TradingSession::query()->count())->toBe(1);
});

it('does not open the session for somebody who may only watch', function () {
    tradingAs(MemberRole::Chairperson);

    $this->get(route('app.trading.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('session', null)
            ->where('abilities.operate', false));

    expect(TradingSession::query()->count())->toBe(0);
});

it('keeps a plain member out of the console entirely', function () {
    tradingAs(MemberRole::Member);

    $this->get(route('app.trading.index'))->assertForbidden();
});

it('marks money received and computes the penalty days from when it arrived', function () {
    tradingAs(MemberRole::Treasurer);
    $session = app(TradingSessionService::class)->openFor($this->january);
    $entry = $session->entries()->first();

    $this->post(route('app.trading.entries.receipt', $entry), [
        'actual_in_ngwee' => 100_000,
        'received_at' => '2026-01-10 11:30',
    ])->assertRedirect();

    expect($entry->refresh()->penalty_days)->toBe(3)
        ->and(Kwacha::toNgwee($entry->actual_in_ngwee))->toBe(100_000);
});

it('will not let the chair mark money at the table', function () {
    tradingAs(MemberRole::Treasurer);
    $entry = app(TradingSessionService::class)->openFor($this->january)->entries()->first();

    tradingAs(MemberRole::Chairperson);

    $this->post(route('app.trading.entries.receipt', $entry), [
        'actual_in_ngwee' => 100_000,
        'received_at' => '2026-01-07 11:30',
    ])->assertForbidden();
});

it('concludes the session and posts the month', function () {
    $treasurer = tradingAs(MemberRole::Treasurer);
    $session = app(TradingSessionService::class)->openFor($this->january);
    $entry = $session->entries()->first();

    app(TradingSessionService::class)->markReceived(
        $entry,
        Kwacha::of(1_000),
        Carbon::parse('2026-01-07 10:00'),
        $treasurer,
    );

    $this->post(route('app.trading.conclude', $session))->assertRedirect();

    expect($session->refresh()->status)->toBe(TradingSessionStatus::Concluded)
        ->and(SavingsTransaction::query()->where('member_id', $this->saver->id)->count())->toBe(1);
});

it('refuses to conclude for anybody without trading.operate', function () {
    tradingAs(MemberRole::Treasurer);
    $session = app(TradingSessionService::class)->openFor($this->january);

    tradingAs(MemberRole::Chairperson);

    $this->post(route('app.trading.conclude', $session))->assertForbidden();
});

it('clears a receipt marked in error', function () {
    $treasurer = tradingAs(MemberRole::Treasurer);
    $session = app(TradingSessionService::class)->openFor($this->january);
    $entry = $session->entries()->first();

    app(TradingSessionService::class)->markReceived(
        $entry,
        Kwacha::of(1_000),
        Carbon::parse('2026-01-07 10:00'),
        $treasurer,
    );

    $this->delete(route('app.trading.entries.receipt.destroy', $entry))->assertRedirect();

    expect($entry->refresh()->received_at)->toBeNull()
        ->and(Kwacha::toNgwee($entry->actual_in_ngwee))->toBe(0);
});
