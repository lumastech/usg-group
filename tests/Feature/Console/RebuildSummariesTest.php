<?php

use App\Domain\Cycles\CurrentCycle;
use App\Domain\Cycles\CycleMonthPlanner;
use App\Domain\Savings\SavingsLedger;
use App\Models\Cycle;
use App\Models\Member;
use App\Models\MemberMonthBalance;
use App\Support\Kwacha;

beforeEach(function () {
    $this->cycle = Cycle::factory()->create();
    $this->months = app(CycleMonthPlanner::class)->plan($this->cycle);
    app(CurrentCycle::class)->set($this->cycle);

    $this->member = Member::factory()->for($this->cycle)->create();
    $this->actor = Member::factory()->for($this->cycle)->create();

    app(SavingsLedger::class)->record(
        $this->member,
        $this->months->firstWhere('sequence', 1),
        Kwacha::of(3000),
        $this->actor,
    );
});

it('rebuilds every month of the current cycle', function () {
    $this->artisan('unity:rebuild-summaries')->assertSuccessful();

    // Two members across all twelve planned months.
    expect(MemberMonthBalance::count())->toBe(24);
});

it('rebuilds a single month when asked', function () {
    $this->artisan('unity:rebuild-summaries', ['--month' => 1])->assertSuccessful();

    expect(MemberMonthBalance::count())->toBe(2);
});

it('produces the same numbers when run again', function () {
    $this->artisan('unity:rebuild-summaries')->assertSuccessful();
    $first = MemberMonthBalance::orderBy('id')->get()->map->toArray();

    $this->artisan('unity:rebuild-summaries')->assertSuccessful();
    $second = MemberMonthBalance::orderBy('id')->get()->map->toArray();

    expect($second)->toEqual($first)
        ->and(MemberMonthBalance::count())->toBe(24);
});

it('picks up a ledger entry posted after the last rebuild', function () {
    $this->artisan('unity:rebuild-summaries', ['--month' => 2])->assertSuccessful();

    app(SavingsLedger::class)->record(
        $this->member,
        $this->months->firstWhere('sequence', 2),
        Kwacha::of(1000),
        $this->actor,
    );

    $this->artisan('unity:rebuild-summaries', ['--month' => 2])->assertSuccessful();

    $balance = MemberMonthBalance::where('member_id', $this->member->id)
        ->where('cycle_month_id', $this->months->firstWhere('sequence', 2)->id)
        ->first();

    expect(Kwacha::format($balance->cumulative_savings_ngwee))->toBe('K4,000.00');
});

it('rebuilds a named cycle instead of the current one', function () {
    $other = Cycle::factory()->create([
        'name' => 'Other',
        'starts_on' => '2024-12-01',
        'ends_on' => '2025-11-30',
    ]);
    app(CycleMonthPlanner::class)->plan($other);
    Member::factory()->for($other)->create();

    $this->artisan('unity:rebuild-summaries', ['--cycle' => $other->id])->assertSuccessful();

    expect(MemberMonthBalance::count())->toBe(12);
});

it('fails when there is no cycle to rebuild', function () {
    Cycle::query()->delete();

    $this->artisan('unity:rebuild-summaries')->assertFailed();
});

it('fails when the cycle has no planned months', function () {
    $unplanned = Cycle::factory()->create(['name' => 'Unplanned', 'starts_on' => '2023-12-01']);

    $this->artisan('unity:rebuild-summaries', ['--cycle' => $unplanned->id])->assertFailed();
});
