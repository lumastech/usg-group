<?php

use App\Domain\Cycles\CycleMonthPlanner;
use App\Domain\Declarations\DeclarationService;
use App\Domain\Loans\LoanApplicationService;
use App\Domain\Loans\LoanDisbursementQueue;
use App\Domain\Savings\SavingsLedger;
use App\Enums\DeclarationStatus;
use App\Enums\MemberRole;
use App\Exceptions\DeclarationLockedException;
use App\Exceptions\InvalidSavingsAmountException;
use App\Exceptions\LoanNotEligibleException;
use App\Exceptions\LockdownSavingsCapException;
use App\Models\Cycle;
use App\Models\Declaration;
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
    $this->february = $this->months->firstWhere('sequence', 3);
    $this->september = $this->months->firstWhere('sequence', 10);

    $this->service = app(DeclarationService::class);
    $this->member = Member::factory()->for($this->cycle)->create();
    $this->treasurer = memberWithRole($this->cycle, MemberRole::Treasurer);
    $this->chair = memberWithRole($this->cycle, MemberRole::Chairperson);

    $this->inWindow = Carbon::parse('2026-01-02 10:00');

    $this->declare = function (int $saving, int $repayment = 0, int $requested = 0, ?Member $member = null) {
        return $this->service->submit(
            $member ?? $this->member,
            $this->january,
            Kwacha::of($saving),
            Kwacha::of($repayment),
            Kwacha::of($requested),
            actor: $member ?? $this->member,
            at: $this->inWindow,
        );
    };
});

it('records a declaration and derives the total expected payment', function () {
    $declaration = ($this->declare)(1_000, 500);

    expect($declaration->totalExpectedNgwee())->toBe(150_000)
        ->and(Kwacha::toNgwee($declaration->total_expected_payment_ngwee))->toBe(150_000)
        ->and($declaration->status)->toBe(DeclarationStatus::Submitted);
});

it('keeps one declaration per member per month, editing it rather than adding another', function () {
    ($this->declare)(500);
    ($this->declare)(1_500);

    $declarations = Declaration::query()->forMonth($this->january)->where('member_id', $this->member->id)->get();

    expect($declarations)->toHaveCount(1)
        ->and(Kwacha::toNgwee($declarations->first()->saving_amount_ngwee))->toBe(150_000);
});

it('allows a negative total expected payment when the loan outweighs what is brought', function () {
    app(SavingsLedger::class)->record($this->member, $this->december, Kwacha::of(5_000), $this->treasurer);

    $declaration = ($this->declare)(500, 0, 5_000);

    expect($declaration->totalExpectedNgwee())->toBe(-450_000)
        ->and(Kwacha::toNgwee($declaration->total_expected_payment_ngwee))->toBe(-450_000);
});

it('refuses savings that break the five hundred kwacha increment', function () {
    ($this->declare)(750);
})->throws(InvalidSavingsAmountException::class, 'increments of K500.00');

it('refuses savings above the lockdown cap in september', function () {
    $this->service->submit(
        $this->member,
        $this->september,
        Kwacha::of(1_000),
        Kwacha::zero(),
        Kwacha::zero(),
        actor: $this->member,
        at: Carbon::parse('2026-09-02 10:00'),
    );
})->throws(LockdownSavingsCapException::class, 'capped at K500.00');

it('refuses a loan request the member is not eligible for', function () {
    ($this->declare)(500, 0, 50_000);
})->throws(LoanNotEligibleException::class, 'may borrow up to 2 times their savings');

it('accepts a loan request within the savings multiple', function () {
    app(SavingsLedger::class)->record($this->member, $this->december, Kwacha::of(5_000), $this->treasurer);

    $declaration = ($this->declare)(500, 0, 10_000);

    expect(Kwacha::toNgwee($declaration->loan_requested_amount_ngwee))->toBe(1_000_000);
});

it('prefills the repayment from the schedule the member is already held to', function () {
    app(SavingsLedger::class)->record($this->member, $this->december, Kwacha::of(5_000), $this->treasurer);

    $applications = app(LoanApplicationService::class);
    $loan = $applications->request($this->member, Kwacha::of(4_000), $this->treasurer, Carbon::parse('2026-01-02 09:00'));
    $applications->approve($loan, $this->chair, $this->treasurer);
    app(LoanDisbursementQueue::class)->disburse($loan->refresh(), $this->january, $this->treasurer);

    $defaults = $this->service->defaultsFor($this->member, $this->february);

    expect($defaults['loan_repayment_amount_ngwee'])->toBeGreaterThan(0)
        ->and($defaults['saving_amount_ngwee'])->toBe(50_000);
});

it('opens on the minimum savings when the member has no schedule', function () {
    expect($this->service->defaultsFor($this->member, $this->january))->toBe([
        'saving_amount_ngwee' => 50_000,
        'loan_repayment_amount_ngwee' => 0,
        'loan_requested_amount_ngwee' => 0,
    ]);
});

it('refuses to change a declaration once it has been locked', function () {
    ($this->declare)(500);
    $this->service->lockMonth($this->january);

    ($this->declare)(1_000);
})->throws(DeclarationLockedException::class, 'is locked and can no longer be changed');

it('lists the active members who have not declared', function () {
    $silent = Member::factory()->for($this->cycle)->create();
    ($this->declare)(500);

    $missing = $this->service->missingFor($this->january);

    expect($missing->pluck('id'))->toContain($silent->id)
        ->and($missing->pluck('id'))->not->toContain($this->member->id);
});
