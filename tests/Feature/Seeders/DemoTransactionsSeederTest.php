<?php

use App\Domain\Cycles\CurrentCycle;
use App\Enums\CycleMonthStatus;
use App\Models\Cycle;
use App\Models\Declaration;
use App\Models\Member;
use App\Models\MemberMonthBalance;
use App\Models\SavingsTransaction;
use App\Models\TradingSession;
use Database\Seeders\DemoTransactionsSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UnityCycleSeeder;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(UnityCycleSeeder::class);

    app(CurrentCycle::class)->forget();
});

it('plays three months through the real services', function () {
    $this->seed(DemoTransactionsSeeder::class);

    $cycle = Cycle::query()->where('name', '2025–2026')->sole();
    $concluded = $cycle->months()->where('status', CycleMonthStatus::Closed)->count();

    expect(Member::query()->forCycle($cycle)->count())->toBe(30)
        ->and($concluded)->toBe(DemoTransactionsSeeder::MONTHS)
        ->and(TradingSession::query()->count())->toBe(DemoTransactionsSeeder::MONTHS)
        ->and(SavingsTransaction::query()->count())->toBeGreaterThan(50);
});

it('leaves some members undeclared, so the chase lists have something to show', function () {
    $this->seed(DemoTransactionsSeeder::class);

    $cycle = Cycle::query()->where('name', '2025–2026')->sole();
    $firstMonth = $cycle->months()->first();

    expect(Declaration::query()->forMonth($firstMonth)->count())
        ->toBeLessThan(Member::query()->forCycle($cycle)->count());
});

it('rebuilds the summaries the dashboards read', function () {
    $this->seed(DemoTransactionsSeeder::class);

    expect(MemberMonthBalance::query()->count())->toBeGreaterThan(0);
});

it('puts the clock back when it is done', function () {
    $this->seed(DemoTransactionsSeeder::class);

    expect(now()->year)->toBeGreaterThanOrEqual(2026);
    expect(Carbon::hasTestNow())->toBeFalse();
});
