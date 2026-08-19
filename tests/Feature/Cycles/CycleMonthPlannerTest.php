<?php

use App\Domain\Cycles\CycleMonthPlanner;
use App\Enums\InterestAllocationMethod;
use App\Enums\WeekendTradingPolicy;
use App\Models\Cycle;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->planner = app(CycleMonthPlanner::class);
});

it('plans twelve months for a december to november cycle', function () {
    $cycle = Cycle::factory()->create();

    $months = $this->planner->plan($cycle);

    expect($months)->toHaveCount(12)
        ->and($months->first()->month->format('Y-m'))->toBe('2025-12')
        ->and($months->last()->month->format('Y-m'))->toBe('2026-11');
});

it('opens the declaration window at 08:00 on the 1st and closes at the end of the 3rd', function () {
    $cycle = Cycle::factory()->create();

    $january = $this->planner->plan($cycle)->firstWhere('sequence', 2);

    expect($january->declarations_open_at->format('Y-m-d H:i'))->toBe('2026-01-01 08:00')
        ->and($january->declarations_close_at->format('Y-m-d H:i'))->toBe('2026-01-03 23:59')
        ->and($january->trading_starts_on->format('Y-m-d'))->toBe('2026-01-04');
});

it('disburses on the 7th when it falls on a weekday', function () {
    $cycle = Cycle::factory()->create();

    $date = $this->planner->disbursementDateFor(Carbon::parse('2026-01-01'), WeekendTradingPolicy::NextMonday);

    expect($date->format('Y-m-d'))->toBe('2026-01-07')
        ->and($date->isWednesday())->toBeTrue();
});

it('moves disbursement to the following monday when the 7th is a weekend', function (string $month, string $expected) {
    $cycle = Cycle::factory()->create();

    $date = $this->planner->disbursementDateFor(Carbon::parse($month), $cycle->weekend_trading_policy);

    expect($date->format('Y-m-d'))->toBe($expected);
})->with([
    'Sunday 7 December 2025' => ['2025-12-01', '2025-12-08'],
    'Saturday 7 February 2026' => ['2026-02-01', '2026-02-09'],
    'Sunday 7 June 2026' => ['2026-06-01', '2026-06-08'],
    'Saturday 7 November 2026' => ['2026-11-01', '2026-11-09'],
]);

it('moves disbursement to the preceding friday when the cycle policy says so', function () {
    $cycle = Cycle::factory()->previousFridayPolicy()->create();

    $date = $this->planner->disbursementDateFor(Carbon::parse('2026-02-01'), $cycle->weekend_trading_policy);

    expect($date->format('Y-m-d'))->toBe('2026-02-06')
        ->and($date->isFriday())->toBeTrue();
});

it('concludes trading on the disbursement date', function () {
    $cycle = Cycle::factory()->create();

    $february = $this->planner->plan($cycle)->firstWhere('sequence', 3);

    expect($february->trading_concludes_on->format('Y-m-d'))
        ->toBe($february->disbursement_on->format('Y-m-d'));
});

it('credits the opening month from own savings and every later month from the pool', function () {
    $cycle = Cycle::factory()->create();

    $months = $this->planner->plan($cycle);

    expect($months->firstWhere('sequence', 1)->interest_allocation_method)
        ->toBe(InterestAllocationMethod::OwnSavingsFlat);

    expect($months->where('sequence', '>', 1)->pluck('interest_allocation_method')->unique()->all())
        ->toBe([InterestAllocationMethod::PooledProRata]);
});

it('can be re-run without duplicating months', function () {
    $cycle = Cycle::factory()->create();

    $this->planner->plan($cycle);
    $this->planner->plan($cycle);

    expect($cycle->months()->count())->toBe(12);
});

it('reports whether the declaration window is open for a given moment', function () {
    $cycle = Cycle::factory()->create();
    $january = $this->planner->plan($cycle)->firstWhere('sequence', 2);

    expect($january->declarationsOpenAt(Carbon::parse('2026-01-01 07:59')))->toBeFalse()
        ->and($january->declarationsOpenAt(Carbon::parse('2026-01-01 08:00')))->toBeTrue()
        ->and($january->declarationsOpenAt(Carbon::parse('2026-01-03 23:00')))->toBeTrue()
        ->and($january->declarationsOpenAt(Carbon::parse('2026-01-04 00:01')))->toBeFalse()
        ->and($january->isLate(Carbon::parse('2026-01-04 00:01')))->toBeTrue();
});
