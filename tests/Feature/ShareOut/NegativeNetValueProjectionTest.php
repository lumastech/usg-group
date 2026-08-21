<?php

use App\Domain\Cycles\CycleMonthPlanner;
use App\Domain\Loans\LoanApplicationService;
use App\Domain\Loans\LoanDisbursementQueue;
use App\Domain\Reporting\NegativeNetValueProjection;
use App\Domain\Savings\SavingsLedger;
use App\Enums\MemberRole;
use App\Models\Cycle;
use App\Support\Kwacha;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->cycle = Cycle::factory()->create();
    $this->months = app(CycleMonthPlanner::class)->plan($this->cycle);
    $this->december = $this->months->firstWhere('sequence', 1);
    $this->january = $this->months->firstWhere('sequence', 2);

    $this->treasurer = memberWithRole($this->cycle, MemberRole::Treasurer);
    $this->chair = memberWithRole($this->cycle, MemberRole::Chairperson);
    $this->member = memberWithRole($this->cycle);

    $this->projection = app(NegativeNetValueProjection::class);
});

/*
 * The arithmetic is the point of this sheet, so it is asserted on its own before
 * anything goes near a ledger: interest on the opening balance, repayment after it,
 * and a balance that genuinely reaches zero at the end of the horizon.
 */
it('amortises a shortfall over three months at 5% a month', function () {
    $schedule = $this->projection->schedule(100_000, 500, 3);

    expect($schedule)->toHaveCount(3);

    /* K1,000 at 5% over 3 months levels at K367.21 — rounded up to the ngwee. */
    expect($schedule[0]['opening_ngwee'])->toBe(100_000)
        ->and($schedule[0]['interest_ngwee'])->toBe(5_000)
        ->and($schedule[0]['repayment_ngwee'])->toBe(36_721)
        ->and($schedule[0]['closing_ngwee'])->toBe(68_279);

    /* The last month clears whatever is left, so the plan really ends at zero. */
    expect($schedule[2]['closing_ngwee'])->toBe(0);
});

it('charges the interest before the repayment lands, as the trading day does', function () {
    $schedule = $this->projection->schedule(100_000, 500, 3);

    foreach ($schedule as $month) {
        expect($month['closing_ngwee'])
            ->toBe($month['opening_ngwee'] + $month['interest_ngwee'] - $month['repayment_ngwee']);
    }
});

it('splits a shortfall evenly when no interest is charged', function () {
    $schedule = $this->projection->schedule(90_000, 0, 3);

    expect(array_column($schedule, 'repayment_ngwee'))->toBe([30_000, 30_000, 30_000])
        ->and($schedule[2]['closing_ngwee'])->toBe(0);
});

it('lists only the members whose loans have outrun their savings', function () {
    Carbon::setTestNow('2026-01-05');

    app(SavingsLedger::class)->record($this->member, $this->december, Kwacha::of(5_000), $this->treasurer);

    $projection = $this->projection->for($this->cycle);

    expect($projection['rows'])->toBeEmpty()
        ->and($projection['totals']['members'])->toBe(0);

    /* K5,000 saved supports a K10,000 loan, which puts them K5,000 under water. */
    $loan = app(LoanApplicationService::class)->request(
        $this->member,
        Kwacha::of(10_000),
        $this->treasurer,
        Carbon::parse('2026-01-02 09:00'),
    );

    app(LoanApplicationService::class)->approve($loan, $this->chair, $this->treasurer);
    app(LoanDisbursementQueue::class)->disburse($loan->refresh(), $this->january, $this->treasurer);

    /* The balance is struck as at today, so move past the disbursement date. */
    Carbon::setTestNow('2026-02-01');

    $projection = $this->projection->for($this->cycle);

    expect($projection['rows'])->toHaveCount(1)
        ->and($projection['rows'][0]['member_id'])->toBe($this->member->id)
        ->and($projection['rows'][0]['shortfall_ngwee'])->toBe(500_000)
        ->and($projection['rows'][0]['net_value_ngwee'])->toBe(-500_000)
        ->and($projection['rows'][0]['schedule'])->toHaveCount(3)
        ->and($projection['totals']['shortfall_ngwee'])->toBe(500_000);

    /* The dashboard tile counts the same rows the full page lists. */
    expect($this->projection->count($this->cycle))->toBe(1);
});
