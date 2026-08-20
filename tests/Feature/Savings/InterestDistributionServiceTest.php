<?php

use App\Domain\Cycles\CycleMonthPlanner;
use App\Domain\Loans\MonthlyInterestIncome;
use App\Domain\Savings\InterestDistributionService;
use App\Domain\Savings\InterestPoolAllocator;
use App\Domain\Savings\SavingsLedger;
use App\Domain\Savings\Strategies\FlatOwnSavingsStrategy;
use App\Domain\Savings\Strategies\PooledProRataStrategy;
use App\Enums\SavingsTransactionType;
use App\Models\Cycle;
use App\Models\CycleMonth;
use App\Models\InterestAllocation;
use App\Models\Member;
use App\Models\MemberMonthBalance;
use App\Support\Kwacha;
use Brick\Money\Money;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->service = app(InterestDistributionService::class);
    $this->ledger = app(SavingsLedger::class);
    $this->cycle = Cycle::factory()->create();
    $this->months = app(CycleMonthPlanner::class)->plan($this->cycle);
    $this->december = $this->months->firstWhere('sequence', 1);
    $this->january = $this->months->firstWhere('sequence', 2);
    $this->actor = Member::factory()->for($this->cycle)->create();

    $this->saver = function (int $kwacha): Member {
        $member = Member::factory()->for($this->cycle)->create();

        $this->ledger->record($member, $this->december, Kwacha::of($kwacha), $this->actor,
            SavingsTransactionType::ImportOpening);

        return $member;
    };
});

it('credits the pool and refreshes the snapshots in one pass', function () {
    $member = ($this->saver)(30000);
    ($this->saver)(10000);

    $this->service->distribute($this->january, $this->actor, Kwacha::of(4000));

    $balance = MemberMonthBalance::where('member_id', $member->id)
        ->where('cycle_month_id', $this->january->id)
        ->first();

    // Three quarters of K40,000 of group savings earns three quarters of the pool.
    expect(Kwacha::format($balance->interest_earned_ngwee))->toBe('K3,000.00')
        ->and(Kwacha::format($balance->cumulative_savings_ngwee))->toBe('K30,000.00')
        ->and(Kwacha::format($balance->net_value_ngwee))->toBe('K33,000.00');
});

it('hands the whole pool to the members, to the last ngwee', function () {
    collect([30000, 10000, 7250, 333])->each(fn (int $kwacha) => ($this->saver)($kwacha));

    $this->service->distribute($this->january, $this->actor, Kwacha::ofNgwee(4_583_251));

    expect((int) InterestAllocation::where('cycle_month_id', $this->january->id)->sum('amount_ngwee'))
        ->toBe(4_583_251);
});

it('takes the pool from the lending engine when none is passed', function () {
    ($this->saver)(30000);

    app()->instance(MonthlyInterestIncome::class, new class implements MonthlyInterestIncome
    {
        public function poolFor(CycleMonth $month): Money
        {
            return Kwacha::of(2500);
        }
    });

    app(InterestDistributionService::class)->distribute($this->january, $this->actor);

    expect((int) InterestAllocation::where('cycle_month_id', $this->january->id)->sum('amount_ngwee'))
        ->toBe(250_000);
});

it('distributes nothing while no lending engine is wired in', function () {
    ($this->saver)(30000);

    $this->service->distribute($this->january, $this->actor);

    expect((int) InterestAllocation::where('cycle_month_id', $this->january->id)->sum('amount_ngwee'))->toBe(0);
});

it('ignores the pool in the opening month and credits the flat rate instead', function () {
    $member = ($this->saver)(30000);

    $this->service->distribute($this->december, $this->actor, Kwacha::of(999999));

    $allocation = InterestAllocation::where('member_id', $member->id)
        ->where('cycle_month_id', $this->december->id)
        ->first();

    expect(Kwacha::format($allocation->amount_ngwee))->toBe('K1,500.00');
});

it('can be re-run for a month without crediting it twice', function () {
    ($this->saver)(30000);
    ($this->saver)(10000);

    $this->service->distribute($this->january, $this->actor, Kwacha::of(4000));
    $this->service->distribute($this->january, $this->actor, Kwacha::of(4000));

    expect((int) InterestAllocation::where('cycle_month_id', $this->january->id)->sum('amount_ngwee'))
        ->toBe(400_000)
        ->and(InterestAllocation::where('cycle_month_id', $this->january->id)->count())->toBe(3);
});

it('logs who distributed the month and how much', function () {
    ($this->saver)(30000);

    $this->service->distribute($this->january, $this->actor, Kwacha::of(4000));

    $activity = Activity::query()->where('log_name', 'money')->latest('id')->first();

    expect($activity->description)->toContain('K4,000.00')
        ->and($activity->properties['actor_member_id'])->toBe($this->actor->id)
        ->and($activity->properties['pool_ngwee'])->toBe(400_000);
});

it('picks the strategy the month nominates', function () {
    expect(app(InterestPoolAllocator::class)->strategyFor($this->december))
        ->toBeInstanceOf(FlatOwnSavingsStrategy::class)
        ->and(app(InterestPoolAllocator::class)->strategyFor($this->january))
        ->toBeInstanceOf(PooledProRataStrategy::class);
});
