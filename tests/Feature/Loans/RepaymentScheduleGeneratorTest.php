<?php

use App\Domain\Cycles\CycleMonthPlanner;
use App\Domain\Loans\LoanTenor;
use App\Domain\Loans\RepaymentScheduleGenerator;
use App\Models\Cycle;
use App\Support\Kwacha;

beforeEach(function () {
    $this->generator = app(RepaymentScheduleGenerator::class);
    $this->cycle = Cycle::factory()->create();
    $this->months = app(CycleMonthPlanner::class)->plan($this->cycle);
});

it('charges five percent a month on the reducing balance', function () {
    $schedule = $this->generator->preview(
        $this->cycle,
        $this->months->firstWhere('sequence', 2),
        LoanTenor::for(Kwacha::of(10_000)),
    );

    expect(array_column($schedule, 'principal_ngwee'))->toBe([250_000, 250_000, 250_000, 250_000])
        ->and(array_column($schedule, 'interest_ngwee'))->toBe([50_000, 37_500, 25_000, 12_500])
        ->and(array_column($schedule, 'amount_due_ngwee'))->toBe([300_000, 287_500, 275_000, 262_500])
        ->and(array_sum(array_column($schedule, 'interest_ngwee')))->toBe(125_000);
});

it('falls due on the adjusted trading date of each month', function () {
    $schedule = $this->generator->preview(
        $this->cycle,
        $this->months->firstWhere('sequence', 2),
        LoanTenor::for(Kwacha::of(10_000)),
    );

    /* The 7th of February and March 2026 both fall on a Saturday. */
    expect(array_column($schedule, 'due_on'))->toBe([
        '2026-02-09', '2026-03-09', '2026-04-07', '2026-05-07',
    ]);
});

it('starts repayment the month after disbursement', function () {
    $schedule = $this->generator->preview(
        $this->cycle,
        $this->months->firstWhere('sequence', 2),
        LoanTenor::for(Kwacha::of(1_000)),
    );

    expect($schedule)->toHaveCount(1)
        ->and($schedule[0]['month_label'])->toBe('February 2026');
});

it('never schedules an installment past the final repayment deadline', function () {
    $schedule = $this->generator->preview(
        $this->cycle,
        $this->months->firstWhere('sequence', 8),
        LoanTenor::for(Kwacha::of(30_000)),
    );

    $due = array_column($schedule, 'due_on');

    expect($schedule)->toHaveCount(4)
        ->and(end($due))->toBe($this->cycle->final_repayment_date->toDateString());
});

it('counts the months a loan disbursed in a given month still has to repay in', function (int $sequence, int $available) {
    expect($this->generator->monthsAvailableFrom(
        $this->cycle,
        $this->months->firstWhere('sequence', $sequence),
    ))->toBe($available);
})->with([
    'december leaves eleven' => [1, 11],
    'january leaves ten' => [2, 10],
    'july leaves four' => [8, 4],
    'october leaves one' => [11, 1],
    'november leaves none' => [12, 0],
]);
