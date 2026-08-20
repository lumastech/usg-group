<?php

use App\Domain\Cycles\CycleMonthPlanner;
use App\Domain\Loans\LoanApplicationService;
use App\Domain\Loans\LoanDisbursementQueue;
use App\Domain\Savings\SavingsLedger;
use App\Enums\LoanStatus;
use App\Enums\LoanTransactionType;
use App\Enums\MemberRole;
use App\Enums\MemberStatus;
use App\Exceptions\DomainRuleException;
use App\Exceptions\LoanNotEligibleException;
use App\Models\Cycle;
use App\Models\Loan;
use App\Models\Member;
use App\Support\Kwacha;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->cycle = Cycle::factory()->create();
    $this->months = app(CycleMonthPlanner::class)->plan($this->cycle);
    $this->december = $this->months->firstWhere('sequence', 1);
    $this->january = $this->months->firstWhere('sequence', 2);

    $this->chair = memberWithRole($this->cycle, MemberRole::Chairperson);
    $this->treasurer = memberWithRole($this->cycle, MemberRole::Treasurer);
    $this->queue = app(LoanDisbursementQueue::class);
    $this->applications = app(LoanApplicationService::class);

    /** An approved loan for a fresh borrower, requested at the given moment. */
    $this->approvedLoan = function (string $requestedAt, int $kwacha = 2_000): Loan {
        $borrower = Member::factory()->for($this->cycle)->create();

        app(SavingsLedger::class)->record($borrower, $this->december, Kwacha::of(5_000), $this->treasurer);

        $loan = $this->applications->request($borrower, Kwacha::of($kwacha), $this->treasurer, Carbon::parse($requestedAt));

        return $this->applications->approve($loan, $this->chair, $this->treasurer);
    };
});

it('queues approved loans in the order they were requested', function () {
    $second = ($this->approvedLoan)('2026-01-02 11:00');
    $first = ($this->approvedLoan)('2026-01-02 08:30');
    $third = ($this->approvedLoan)('2026-01-03 07:15');

    expect($this->queue->pending($this->january)->pluck('id')->all())
        ->toBe([$first->id, $second->id, $third->id])
        ->and($this->queue->positionOf($second, $this->january))->toBe(2);
});

it('disburses the head of the queue without a reason', function () {
    $loan = ($this->approvedLoan)('2026-01-02 08:30');

    $disbursed = $this->queue->disburse($loan, $this->january, $this->treasurer);

    expect($disbursed->status)->toBe(LoanStatus::Disbursed)
        ->and($disbursed->disbursement_position)->toBe(1)
        ->and($disbursed->out_of_order_reason)->toBeNull()
        ->and($disbursed->current_balance_ngwee->getMinorAmount()->toInt())->toBe(200_000)
        ->and($disbursed->scheduleItems()->count())->toBe(1);
});

it('posts the disbursement to the ledger on the trading date', function () {
    $loan = ($this->approvedLoan)('2026-01-02 08:30');

    $this->queue->disburse($loan, $this->january, $this->treasurer);

    $entry = $loan->transactions()->first();

    expect($entry->type)->toBe(LoanTransactionType::Disbursement)
        ->and($entry->occurred_on->toDateString())->toBe($this->january->disbursement_on->toDateString())
        ->and($entry->balance_after_ngwee->getMinorAmount()->toInt())->toBe(200_000);
});

it('refuses to jump the queue without a typed reason', function () {
    ($this->approvedLoan)('2026-01-02 08:30');
    $later = ($this->approvedLoan)('2026-01-02 11:00');

    $this->queue->disburse($later, $this->january, $this->treasurer);
})->throws(DomainRuleException::class, 'needs a written reason');

it('allows a jump on the record when a reason is given', function () {
    ($this->approvedLoan)('2026-01-02 08:30');
    $later = ($this->approvedLoan)('2026-01-02 11:00');

    $disbursed = $this->queue->disburse(
        $later,
        $this->january,
        $this->treasurer,
        'Medical emergency; agreed by the committee on the day.',
    );

    expect($disbursed->out_of_order_reason)->toBe('Medical emergency; agreed by the committee on the day.');
});

it('numbers each payout on the day in the order it was made', function () {
    $first = ($this->approvedLoan)('2026-01-02 08:30');
    $second = ($this->approvedLoan)('2026-01-02 11:00');

    $this->queue->disburse($first, $this->january, $this->treasurer);
    $this->queue->disburse($second->refresh(), $this->january, $this->treasurer);

    expect($second->refresh()->disbursement_position)->toBe(2);
});

it('re-checks eligibility at the window and refuses a member who has since left', function () {
    $loan = ($this->approvedLoan)('2026-01-02 08:30');
    $loan->member->forceFill(['status' => MemberStatus::LeftEarly])->save();

    $this->queue->disburse($loan->refresh()->load('member'), $this->january, $this->treasurer);
})->throws(LoanNotEligibleException::class, 'may not borrow');

it('only disburses a loan that has been approved', function () {
    $borrower = Member::factory()->for($this->cycle)->create();
    app(SavingsLedger::class)->record($borrower, $this->december, Kwacha::of(5_000), $this->treasurer);

    $loan = $this->applications->request($borrower, Kwacha::of(2_000), $this->treasurer, Carbon::parse('2026-01-02'));

    $this->queue->disburse($loan, $this->january, $this->treasurer);
})->throws(DomainRuleException::class, 'Only an approved loan can be disbursed');
