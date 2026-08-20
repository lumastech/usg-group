<?php

use App\Domain\Cycles\CurrentCycle;
use App\Domain\Cycles\CycleMonthPlanner;
use App\Domain\Declarations\DeclarationService;
use App\Enums\DeclarationStatus;
use App\Models\Cycle;
use App\Models\Member;
use App\Models\TradingSession;
use App\Support\Kwacha;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->cycle = Cycle::factory()->create();
    $this->months = app(CycleMonthPlanner::class)->plan($this->cycle);
    app(CurrentCycle::class)->set($this->cycle);

    $this->january = $this->months->firstWhere('sequence', 2);
    $this->member = Member::factory()->for($this->cycle)->create();
});

it('opens the session on the morning after the window closes', function () {
    $this->travelTo(Carbon::parse('2026-01-02 10:00'));

    $declaration = app(DeclarationService::class)->submit(
        $this->member,
        $this->january,
        Kwacha::of(1_000),
        Kwacha::zero(),
        Kwacha::zero(),
        actor: $this->member,
    );

    $this->travelTo(Carbon::parse('2026-01-04 06:00'));

    $this->artisan('unity:open-trading-sessions')->assertSuccessful();

    $session = TradingSession::query()->where('cycle_month_id', $this->january->id)->first();

    expect($session)->not->toBeNull()
        ->and($session->entries()->count())->toBe(1)
        ->and($declaration->refresh()->status)->toBe(DeclarationStatus::Locked);
});

it('opens nothing while the window is still running', function () {
    $this->travelTo(Carbon::parse('2026-01-02 10:00'));

    $this->artisan('unity:open-trading-sessions')->assertSuccessful();

    expect(TradingSession::query()->count())->toBe(0);
});

it('is safe to run every day of the trading week', function () {
    $this->travelTo(Carbon::parse('2026-01-04 06:00'));
    $this->artisan('unity:open-trading-sessions')->assertSuccessful();

    $this->travelTo(Carbon::parse('2026-01-06 06:00'));
    $this->artisan('unity:open-trading-sessions')->assertSuccessful();

    expect(TradingSession::query()->count())->toBe(1);
});
