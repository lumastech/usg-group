<?php

use App\Domain\Cycles\CycleMonthPlanner;
use App\Domain\Loans\InterestEngine;
use App\Domain\Loans\LoanApplicationService;
use App\Domain\Loans\LoanDisbursementQueue;
use App\Domain\Loans\LoanLedger;
use App\Domain\Loans\LoanRepaymentService;
use App\Domain\Loans\PenaltyService;
use App\Domain\Savings\SavingsLedger;
use App\Enums\LoanScheduleItemStatus;
use App\Enums\LoanStatus;
use App\Enums\LoanTransactionType;
use App\Enums\MemberRole;
use App\Models\Cycle;
use App\Support\Kwacha;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;

/*
 * The worked example the group uses to explain lending: K10,000 borrowed in January,
 * repaid over four months. Every figure below is the exact ngwee the ledger must hold.
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->cycle = Cycle::factory()->create();
    $this->months = app(CycleMonthPlanner::class)->plan($this->cycle);
    $this->january = $this->months->firstWhere('sequence', 2);

    $this->borrower = memberWithRole($this->cycle);
    $this->chair = memberWithRole($this->cycle, MemberRole::Chairperson);
    $this->treasurer = memberWithRole($this->cycle, MemberRole::Treasurer);

    app(SavingsLedger::class)->record(
        $this->borrower,
        $this->months->firstWhere('sequence', 1),
        Kwacha::of(5_000),
        $this->treasurer,
    );

    $this->loan = app(LoanApplicationService::class)->request(
        $this->borrower,
        Kwacha::of(10_000),
        $this->treasurer,
        Carbon::parse('2026-01-02 09:00'),
    );

    app(LoanApplicationService::class)->approve($this->loan, $this->chair, $this->treasurer);

    $this->loan = app(LoanDisbursementQueue::class)->disburse(
        $this->loan->refresh(),
        $this->january,
        $this->treasurer,
    );

    $this->ledger = app(LoanLedger::class);
});

it('issues a four month schedule with reducing interest at disbursement', function () {
    $items = $this->loan->scheduleItems()->get();

    expect($this->loan->status)->toBe(LoanStatus::Disbursed)
        ->and($this->loan->current_balance_ngwee->getMinorAmount()->toInt())->toBe(1_000_000)
        ->and($items->pluck('original_amount_due_ngwee')->map(fn ($m) => $m->getMinorAmount()->toInt())->all())
        ->toBe([300_000, 287_500, 275_000, 262_500]);
});

it('runs the full four month lifecycle to a settled balance of zero', function () {
    $engine = app(InterestEngine::class);
    $repayments = app(LoanRepaymentService::class);
    $penalties = app(PenaltyService::class);

    $expected = [
        ['sequence' => 3, 'interest' => 50_000, 'due' => 300_000, 'balance_after_interest' => 1_050_000],
        ['sequence' => 4, 'interest' => 37_500, 'due' => 287_500, 'balance_after_interest' => 787_500],
        ['sequence' => 5, 'interest' => 25_000, 'due' => 275_000, 'balance_after_interest' => 525_000],
        ['sequence' => 6, 'interest' => 12_500, 'due' => 262_500, 'balance_after_interest' => 262_500],
    ];

    foreach ($expected as $step) {
        $month = $this->months->firstWhere('sequence', $step['sequence']);

        $charge = $engine->postFor($this->loan->refresh(), $month);

        expect($charge->amount_ngwee->getMinorAmount()->toInt())->toBe($step['interest'])
            ->and($this->ledger->balanceNgwee($this->loan->refresh()))->toBe($step['balance_after_interest']);

        $item = $this->loan->scheduleItems()->where('cycle_month_id', $month->id)->first();
        expect($item->amount_due_ngwee->getMinorAmount()->toInt())->toBe($step['due']);

        $repayments->record(
            $this->loan->refresh(),
            Kwacha::ofNgwee($step['due']),
            $this->treasurer,
            $month->disbursement_on,
        );

        expect($item->refresh()->status)->toBe(LoanScheduleItemStatus::Paid);

        $penalties->closeMonth($month, $this->treasurer);
    }

    $this->loan->refresh();

    expect($this->ledger->balanceNgwee($this->loan))->toBe(0)
        ->and($this->loan->status)->toBe(LoanStatus::Settled)
        ->and($this->loan->settled_at)->not->toBeNull()
        ->and($this->loan->interestPaidNgwee())->toBe(125_000)
        ->and($this->loan->principalOutstandingNgwee())->toBe(0)
        ->and($this->loan->penaltiesChargedNgwee())->toBe(0);
});

it('posts every charge and payment to the ledger in order', function () {
    $engine = app(InterestEngine::class);
    $repayments = app(LoanRepaymentService::class);

    foreach ([3, 4, 5, 6] as $sequence) {
        $month = $this->months->firstWhere('sequence', $sequence);
        $engine->postFor($this->loan->refresh(), $month);

        $due = $this->loan->scheduleItems()->where('cycle_month_id', $month->id)->first();

        $repayments->record(
            $this->loan->refresh(),
            $due->amount_due_ngwee,
            $this->treasurer,
            $month->disbursement_on,
        );
    }

    $entries = $this->loan->transactions()->get();

    expect($entries->pluck('type')->all())->toBe([
        LoanTransactionType::Disbursement,
        LoanTransactionType::InterestCharge,
        LoanTransactionType::Repayment,
        LoanTransactionType::InterestCharge,
        LoanTransactionType::Repayment,
        LoanTransactionType::InterestCharge,
        LoanTransactionType::Repayment,
        LoanTransactionType::InterestCharge,
        LoanTransactionType::Repayment,
    ])->and($entries->last()->balance_after_ngwee->getMinorAmount()->toInt())->toBe(0);
});

it('moves the loan to repaying once the first interest is charged', function () {
    app(InterestEngine::class)->postFor($this->loan, $this->months->firstWhere('sequence', 3));

    expect($this->loan->refresh()->status)->toBe(LoanStatus::Repaying);
});

it('never charges the same month twice, however often the trading job runs', function () {
    $february = $this->months->firstWhere('sequence', 3);
    $engine = app(InterestEngine::class);

    $engine->postForMonth($february);
    $engine->postForMonth($february);
    $engine->postForMonth($february);

    expect($this->loan->transactions()->where('type', LoanTransactionType::InterestCharge->value)->count())->toBe(1);
});
