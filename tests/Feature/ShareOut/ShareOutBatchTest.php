<?php

use App\Domain\Cycles\CycleMonthPlanner;
use App\Domain\Savings\SavingsLedger;
use App\Domain\ShareOut\ShareOutBatchRunner;
use App\Enums\CycleStatus;
use App\Enums\MemberRole;
use App\Enums\MemberStatus;
use App\Exceptions\DomainRuleException;
use App\Models\Cycle;
use App\Models\Payout;
use App\Support\Kwacha;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2025-12-01');

    $this->seed(RoleSeeder::class);

    $this->cycle = Cycle::factory()->create();
    $this->months = app(CycleMonthPlanner::class)->plan($this->cycle);
    $this->december = $this->months->firstWhere('sequence', 1);

    $this->treasurer = memberWithRole($this->cycle, MemberRole::Treasurer);
    $this->chair = memberWithRole($this->cycle, MemberRole::Chairperson);
    $this->first = memberWithRole($this->cycle);
    $this->second = memberWithRole($this->cycle);

    $savings = app(SavingsLedger::class);

    foreach ([$this->treasurer, $this->chair, $this->first, $this->second] as $member) {
        $savings->record($member, $this->december, Kwacha::of(1_000), $this->treasurer);
    }

    $this->runner = app(ShareOutBatchRunner::class);

    $this->shareOut = function (): void {
        $this->cycle->forceFill(['status' => CycleStatus::ShareOut])->save();
        $this->cycle->refresh();
    };
});

it('refuses to run before share-out has been opened', function () {
    expect(fn () => $this->runner->run($this->cycle, $this->treasurer, $this->chair))
        ->toThrow(DomainRuleException::class, 'Open share-out before running the batch');
});

it('settles every member still standing and freezes their ledgers', function () {
    ($this->shareOut)();

    expect($this->runner->candidates($this->cycle))->toHaveCount(4);

    $result = $this->runner->run($this->cycle, $this->treasurer, $this->chair);

    /* The two signatories cannot stand behind their own closures, so the batch
       reports them by name and settles the rest. They are settled afterwards by a
       different pair on the closures screen. */
    expect($result['settled_count'])->toBe(2)
        ->and($result['skipped_count'])->toBe(2)
        ->and($result['paid_ngwee'])->toBe(200_000)
        ->and(collect($result['skipped'])->pluck('full_name'))
        ->toContain($this->treasurer->full_name, $this->chair->full_name)
        ->and($this->first->refresh()->ledgersFrozen())->toBeTrue();

    /* Nobody settleable is left, so a second run doubles nothing up. */
    expect($this->runner->run($this->cycle, $this->treasurer, $this->chair)['settled_count'])->toBe(0)
        ->and(Payout::query()->acrossCycles()->count())->toBe(2);
});

it('leaves the exits to the closures screen', function () {
    $this->second->forceFill([
        'status' => MemberStatus::LeftEarly,
        'status_effective_on' => Carbon::parse('2026-03-01'),
    ])->save();

    ($this->shareOut)();

    expect($this->runner->candidates($this->cycle)->pluck('id'))
        ->not->toContain($this->second->id)
        ->and($this->runner->candidates($this->cycle))->toHaveCount(3);
});

it('steps over a member it cannot settle rather than rolling the batch back', function () {
    ($this->shareOut)();

    /* The first pass leaves the two signatories unsettled; the second must step over
       the members it already froze and still settle the newcomer. */
    $this->runner->run($this->cycle, $this->treasurer, $this->chair);

    $latecomer = memberWithRole($this->cycle);
    app(SavingsLedger::class)->record($latecomer, $this->december, Kwacha::of(1_000), $this->treasurer);

    $result = $this->runner->run($this->cycle, $this->treasurer, $this->chair);

    expect($result['settled_count'])->toBe(1)
        ->and($result['settled'][0]['full_name'])->toBe($latecomer->full_name);
});

it('previews what the batch would pay without writing anything', function () {
    ($this->shareOut)();

    $preview = $this->runner->preview($this->cycle);

    expect($preview)->toHaveCount(4)
        ->and($preview->sum('payable_ngwee'))->toBe(400_000)
        ->and(Payout::query()->acrossCycles()->count())->toBe(0);
});

it('builds the master schedule in member-number order', function () {
    ($this->shareOut)();
    $this->runner->run($this->cycle, $this->treasurer, $this->chair);

    $schedule = $this->runner->schedule($this->cycle);

    expect($schedule['count'])->toBe(2)
        ->and($schedule['total_ngwee'])->toBe(200_000)
        ->and(array_column($schedule['rows'], 'member_number'))
        ->toBe(collect($schedule['rows'])->pluck('member_number')->sort()->values()->all());
});
