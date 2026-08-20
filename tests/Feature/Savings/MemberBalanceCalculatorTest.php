<?php

use App\Domain\Cycles\CycleMonthPlanner;
use App\Domain\Loans\OutstandingLoanProvider;
use App\Domain\Savings\InterestPoolAllocator;
use App\Domain\Savings\MemberBalanceCalculator;
use App\Domain\Savings\SavingsLedger;
use App\Enums\SavingsTransactionType;
use App\Models\Cycle;
use App\Models\CycleMonth;
use App\Models\Member;
use App\Models\MemberMonthBalance;
use App\Support\Kwacha;
use Brick\Money\Money;

beforeEach(function () {
    $this->ledger = app(SavingsLedger::class);
    $this->cycle = Cycle::factory()->create();
    $this->months = app(CycleMonthPlanner::class)->plan($this->cycle);
    $this->december = $this->months->firstWhere('sequence', 1);
    $this->january = $this->months->firstWhere('sequence', 2);
    $this->member = Member::factory()->for($this->cycle)->create();
    $this->actor = Member::factory()->for($this->cycle)->create();
});

it('snapshots the month and the running totals behind it', function () {
    $this->ledger->record($this->member, $this->december, Kwacha::of(3000), $this->actor);
    $this->ledger->record($this->member, $this->january, Kwacha::of(1000), $this->actor);

    $balance = app(MemberBalanceCalculator::class)->rebuildFor($this->member, $this->january);

    expect(Kwacha::format($balance->savings_ngwee))->toBe('K1,000.00')
        ->and(Kwacha::format($balance->cumulative_savings_ngwee))->toBe('K4,000.00');
});

it('makes net value savings plus interest while nobody can borrow', function () {
    $this->ledger->record($this->member, $this->december, Kwacha::of(30000), $this->actor);
    app(InterestPoolAllocator::class)->allocate($this->december, Kwacha::zero());

    $balance = app(MemberBalanceCalculator::class)->rebuildFor($this->member, $this->december);

    // December credits a flat 5% of the member's own savings: K1,500 on K30,000.
    expect(Kwacha::format($balance->cumulative_interest_earned_ngwee))->toBe('K1,500.00')
        ->and(Kwacha::format($balance->member_value_ngwee))->toBe('K31,500.00')
        ->and(Kwacha::format($balance->net_value_ngwee))->toBe('K31,500.00')
        ->and(Kwacha::format($balance->loan_balance_ngwee))->toBe('K0.00');
});

it('subtracts what the member owes once a lending engine answers', function () {
    $this->ledger->record($this->member, $this->december, Kwacha::of(30000), $this->actor);

    // Standing in for module 3: this member owes K50,000, which puts them under water.
    app()->instance(OutstandingLoanProvider::class, new class implements OutstandingLoanProvider
    {
        public function balanceFor(Member $member, CycleMonth $month): Money
        {
            return Kwacha::of(50000);
        }

        public function socialFundBalanceFor(Member $member, CycleMonth $month): Money
        {
            return Kwacha::of(2000);
        }

        public function interestPaidTo(Member $member, CycleMonth $month): Money
        {
            return Kwacha::of(1200);
        }

        public function borrowedToDate(Member $member, CycleMonth $month): Money
        {
            return Kwacha::of(60000);
        }
    });

    $balance = app(MemberBalanceCalculator::class)->rebuildFor($this->member, $this->december);

    expect(Kwacha::format($balance->member_value_ngwee))->toBe('K30,000.00')
        ->and(Kwacha::format($balance->net_value_ngwee))->toBe('-K22,000.00')
        ->and($balance->hasNegativeNetValue())->toBeTrue()
        ->and(Kwacha::format($balance->cumulative_interest_paid_ngwee))->toBe('K1,200.00');
});

it('reports what a member may still borrow against twice their savings', function () {
    $this->ledger->record($this->member, $this->december, Kwacha::of(20000), $this->actor);

    $balance = app(MemberBalanceCalculator::class)->rebuildFor($this->member, $this->december);

    expect(Kwacha::format($balance->two_times_savings_ngwee))->toBe('K40,000.00')
        ->and(Kwacha::format($balance->eligible_to_borrow_ngwee))->toBe('K40,000.00');
});

it('rebuilds every member of the month in one pass', function () {
    Member::factory()->count(3)->for($this->cycle)->create();

    $rebuilt = app(MemberBalanceCalculator::class)->rebuildMonth($this->december);

    expect($rebuilt)->toHaveCount(5)
        ->and(MemberMonthBalance::where('cycle_month_id', $this->december->id)->count())->toBe(5);
});

it('is idempotent: rebuilding twice changes nothing and adds no rows', function () {
    $this->ledger->record($this->member, $this->december, Kwacha::of(3000), $this->actor);
    $this->ledger->record($this->member, $this->january, Kwacha::of(1000), $this->actor);
    app(InterestPoolAllocator::class)->allocate($this->december, Kwacha::zero());

    $calculator = app(MemberBalanceCalculator::class);

    $calculator->rebuildMonth($this->december);
    $calculator->rebuildMonth($this->january);
    $first = MemberMonthBalance::orderBy('id')->get()->map->toArray();

    $calculator->rebuildMonth($this->december);
    $calculator->rebuildMonth($this->january);
    $second = MemberMonthBalance::orderBy('id')->get()->map->toArray();

    expect($second)->toEqual($first)
        ->and(MemberMonthBalance::count())->toBe(4);
});

it('rebuilds correctly after a reversing adjustment', function () {
    $this->ledger->record($this->member, $this->december, Kwacha::of(3000), $this->actor);
    app(MemberBalanceCalculator::class)->rebuildFor($this->member, $this->december);

    $this->ledger->record(
        $this->member,
        $this->december,
        Kwacha::of(1000)->negated(),
        $this->actor,
        SavingsTransactionType::Adjustment,
    );

    $balance = app(MemberBalanceCalculator::class)->rebuildFor($this->member, $this->december);

    expect(Kwacha::format($balance->cumulative_savings_ngwee))->toBe('K2,000.00');
});
