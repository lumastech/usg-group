<?php

use App\Domain\Cycles\CycleMonthPlanner;
use App\Domain\Savings\InterestPoolAllocator;
use App\Domain\Savings\SavingsLedger;
use App\Enums\SavingsTransactionType;
use App\Models\Cycle;
use App\Models\InterestAllocation;
use App\Models\Member;
use App\Support\Kwacha;

beforeEach(function () {
    $this->allocator = app(InterestPoolAllocator::class);
    $this->ledger = app(SavingsLedger::class);
    $this->cycle = Cycle::factory()->create();
    $this->months = app(CycleMonthPlanner::class)->plan($this->cycle);
    $this->december = $this->months->firstWhere('sequence', 1);
    $this->january = $this->months->firstWhere('sequence', 2);
    $this->actor = Member::factory()->for($this->cycle)->create();

    /** Saves an opening balance for a new member without tripping the increment rules. */
    $this->memberSaving = function (int $december, int $january = 0): Member {
        $member = Member::factory()->for($this->cycle)->create();

        $this->ledger->record($member, $this->december, Kwacha::of($december), $this->actor,
            SavingsTransactionType::ImportOpening);

        if ($january > 0) {
            $this->ledger->record($member, $this->january, Kwacha::of($january), $this->actor,
                SavingsTransactionType::ImportOpening);
        }

        return $member;
    };
});

it('credits the opening month at five percent of the members own savings', function () {
    $member = ($this->memberSaving)(30000);

    $allocations = $this->allocator->allocate($this->december, Kwacha::zero());

    expect(Kwacha::format($allocations->firstWhere('member_id', $member->id)->amount_ngwee))
        ->toBe('K1,500.00');
});

it('splits a later months pool pro rata by cumulative savings', function () {
    // The real January 2026 figures from the workbook: a K45,832.50 pool shared
    // across K883,500 of cumulative group savings.
    $bernadette = ($this->memberSaving)(0, 2000);
    $bertha = ($this->memberSaving)(30000, 1000);
    $rest = ($this->memberSaving)(633000, 217500);

    $this->allocator->allocate($this->january, Kwacha::of('45832.50'));

    $allocations = $this->january->load('cycle')->cycle->members()->get()
        ->mapWithKeys(fn (Member $m): array => [
            $m->id => InterestAllocation::where('member_id', $m->id)
                ->where('cycle_month_id', $this->january->id)->first(),
        ]);

    // The workbook carries these as unrounded floats: K103.7521 and K1,608.1579.
    // Rounded to whole ngwee they come to K103.75 and K1,608.16, and the three
    // shares add back up to the pool exactly.
    expect(Kwacha::format($allocations[$bernadette->id]->amount_ngwee))->toBe('K103.75')
        ->and(Kwacha::format($allocations[$bertha->id]->amount_ngwee))->toBe('K1,608.16')
        ->and(Kwacha::format($allocations[$rest->id]->amount_ngwee))->toBe('K44,120.59')
        ->and((int) InterestAllocation::where('cycle_month_id', $this->january->id)->sum('amount_ngwee'))
        ->toBe(4_583_250);
});

it('records the basis it used so an allocation can be explained', function () {
    $member = ($this->memberSaving)(30000, 1000);
    ($this->memberSaving)(633000, 217500);
    ($this->memberSaving)(0, 2000);

    $this->allocator->allocate($this->january, Kwacha::of('45832.50'));

    $allocation = InterestAllocation::where('member_id', $member->id)->first();

    expect(Kwacha::format($allocation->member_basis_ngwee))->toBe('K31,000.00')
        ->and(Kwacha::format($allocation->pool_basis_ngwee))->toBe('K883,500.00')
        ->and(Kwacha::format($allocation->pool_total_ngwee))->toBe('K45,832.50');
});

it('never loses or invents a ngwee, whatever the split', function (int $poolNgwee) {
    collect([2000, 31000, 15500, 500, 7250, 100000, 333])
        ->each(fn (int $kwacha) => ($this->memberSaving)($kwacha));

    $this->allocator->allocate($this->january, Kwacha::ofNgwee($poolNgwee));

    $total = InterestAllocation::where('cycle_month_id', $this->january->id)->sum('amount_ngwee');

    expect((int) $total)->toBe($poolNgwee);
})->with([1, 7, 99, 4_583_250, 1_000_000, 12_345_679]);

it('gives every member nothing when nobody has saved yet', function () {
    Member::factory()->count(3)->for($this->cycle)->create();

    $this->allocator->allocate($this->january, Kwacha::of(500));

    $total = InterestAllocation::where('cycle_month_id', $this->january->id)->sum('amount_ngwee');

    expect((int) $total)->toBe(0);
});

it('is stable when re-run for the same month', function () {
    ($this->memberSaving)(30000, 1000);
    ($this->memberSaving)(633000, 217500);

    $this->allocator->allocate($this->january, Kwacha::of('45832.50'));
    $first = InterestAllocation::where('cycle_month_id', $this->january->id)
        ->orderBy('member_id')->pluck('amount_ngwee')->map(fn ($m) => Kwacha::toNgwee($m))->all();

    $this->allocator->allocate($this->january, Kwacha::of('45832.50'));
    $second = InterestAllocation::where('cycle_month_id', $this->january->id)
        ->orderBy('member_id')->pluck('amount_ngwee')->map(fn ($m) => Kwacha::toNgwee($m))->all();

    expect($second)->toBe($first)
        ->and(InterestAllocation::where('cycle_month_id', $this->january->id)->count())->toBe(3);
});

it('hands the leftover ngwee to the largest fractional shares first', function () {
    $shares = $this->allocator->largestRemainder([1 => 1, 2 => 1, 3 => 1], 3, 10);

    expect(array_sum(array_column($shares, 'amount')))->toBe(10)
        ->and($shares[1]['amount'])->toBe(4)
        ->and($shares[2]['amount'])->toBe(3)
        ->and($shares[3]['amount'])->toBe(3);
});
