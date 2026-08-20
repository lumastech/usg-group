<?php

use App\Domain\Cycles\CycleMonthPlanner;
use App\Domain\Loans\LoanApplicationService;
use App\Domain\Savings\SavingsLedger;
use App\Enums\LoanStatus;
use App\Enums\MemberRole;
use App\Exceptions\DomainRuleException;
use App\Exceptions\LoanNotEligibleException;
use App\Models\Cycle;
use App\Models\Loan;
use App\Support\Kwacha;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->service = app(LoanApplicationService::class);
    $this->cycle = Cycle::factory()->create();
    $this->months = app(CycleMonthPlanner::class)->plan($this->cycle);

    $this->borrower = memberWithRole($this->cycle);
    $this->chair = memberWithRole($this->cycle, MemberRole::Chairperson);
    $this->viceChair = memberWithRole($this->cycle, MemberRole::ViceChairperson);
    $this->treasurer = memberWithRole($this->cycle, MemberRole::Treasurer);
    $this->plainMember = memberWithRole($this->cycle);

    app(SavingsLedger::class)->record(
        $this->borrower,
        $this->months->firstWhere('sequence', 1),
        Kwacha::of(5_000),
        $this->treasurer,
    );

    $this->request = fn (int $kwacha = 10_000, ?string $note = null): Loan => $this->service->request(
        $this->borrower,
        Kwacha::of($kwacha),
        $this->treasurer,
        Carbon::parse('2026-01-02 09:00'),
        $note,
    );
});

it('records a request with the tenor the principal earns', function () {
    $loan = ($this->request)();

    expect($loan->status)->toBe(LoanStatus::Requested)
        ->and($loan->tenor_months)->toBe(4)
        ->and($loan->schedule_compressed)->toBeFalse()
        ->and($loan->principal_ngwee->getMinorAmount()->toInt())->toBe(1_000_000);
});

it('refuses a request the member is not eligible for and names every reason', function () {
    try {
        ($this->request)(20_000);
        $this->fail('The request should have been refused.');
    } catch (LoanNotEligibleException $exception) {
        expect($exception->reasons())->toHaveCount(1)
            ->and($exception->getMessage())->toContain('ceiling is K10,000.00');
    }
});

it('stores the written reason when a committee member overrides the one loan rule', function () {
    ($this->request)(2_000);

    $second = ($this->request)(2_000, 'Funeral costs, agreed at the January meeting.');

    expect($second->discretion_override)->toBeTrue()
        ->and($second->discretion_note)->toBe('Funeral costs, agreed at the January meeting.');
});

it('does not let an ordinary member override the one loan rule', function () {
    ($this->request)(2_000);

    $this->service->request(
        $this->borrower,
        Kwacha::of(2_000),
        $this->plainMember,
        Carbon::parse('2026-01-02 09:00'),
        'I said it was fine.',
    );
})->throws(DomainRuleException::class, 'Only a committee member');

it('approves on two distinct committee signatures and keeps both', function () {
    $loan = $this->service->approve(($this->request)(), $this->chair, $this->viceChair);

    expect($loan->status)->toBe(LoanStatus::Approved)
        ->and($loan->approved_by_member_id)->toBe($this->chair->id)
        ->and($loan->second_approver_member_id)->toBe($this->viceChair->id)
        ->and($loan->approved_at)->not->toBeNull();
});

it('refuses the same committee member signing twice', function () {
    $this->service->approve(($this->request)(), $this->chair, $this->chair);
})->throws(DomainRuleException::class, 'second, different committee member');

it('refuses a second signature from someone off the committee', function () {
    $this->service->approve(($this->request)(), $this->chair, $this->plainMember);
})->throws(DomainRuleException::class, 'does not sit on the committee');

it('refuses to let the borrower stand as one of their own approvers', function () {
    $borrower = memberWithRole($this->cycle, MemberRole::Treasurer);
    app(SavingsLedger::class)->record(
        $borrower,
        $this->months->firstWhere('sequence', 1),
        Kwacha::of(5_000),
        $this->treasurer,
    );

    $loan = $this->service->request($borrower, Kwacha::of(2_000), $this->treasurer, Carbon::parse('2026-01-02'));

    $this->service->approve($loan, $this->chair, $borrower);
})->throws(DomainRuleException::class, 'cannot stand as an approver on their own request');

it('only approves a loan that is still merely requested', function () {
    $loan = $this->service->approve(($this->request)(), $this->chair, $this->viceChair);

    $this->service->approve($loan, $this->chair, $this->viceChair);
})->throws(DomainRuleException::class, 'Only a requested loan can be approved');

it('records a rejection with its reason', function () {
    $loan = $this->service->reject(($this->request)(), $this->chair, 'Savings still short of the ceiling.');

    expect($loan->status)->toBe(LoanStatus::Rejected)
        ->and($loan->rejection_reason)->toBe('Savings still short of the ceiling.')
        ->and($loan->rejected_by_member_id)->toBe($this->chair->id);
});
