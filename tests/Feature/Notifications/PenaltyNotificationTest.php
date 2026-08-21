<?php

use App\Domain\Cycles\CycleMonthPlanner;
use App\Domain\Loans\InterestEngine;
use App\Domain\Loans\LoanApplicationService;
use App\Domain\Loans\LoanDisbursementQueue;
use App\Domain\Loans\LoanRepaymentService;
use App\Domain\Loans\PenaltyService;
use App\Domain\Notifications\PenaltyNotifier;
use App\Domain\Savings\SavingsLedger;
use App\Enums\MemberRole;
use App\Models\Cycle;
use App\Notifications\PenaltyApplied;
use App\Support\Kwacha;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    $this->seed(RoleSeeder::class);

    $this->cycle = Cycle::factory()->create();
    $this->months = app(CycleMonthPlanner::class)->plan($this->cycle);
    $this->january = $this->months->firstWhere('sequence', 2);
    $this->february = $this->months->firstWhere('sequence', 3);

    $this->borrower = memberWithRole($this->cycle);
    $this->chair = memberWithRole($this->cycle, MemberRole::Chairperson);
    $this->treasurer = memberWithRole($this->cycle, MemberRole::Treasurer);

    app(SavingsLedger::class)->record(
        $this->borrower,
        $this->months->firstWhere('sequence', 1),
        Kwacha::of(5_000),
        $this->treasurer,
    );

    $loan = app(LoanApplicationService::class)->request(
        $this->borrower,
        Kwacha::of(10_000),
        $this->treasurer,
        Carbon::parse('2026-01-02 09:00'),
    );

    app(LoanApplicationService::class)->approve($loan, $this->chair, $this->treasurer);
    $this->loan = app(LoanDisbursementQueue::class)->disburse($loan->refresh(), $this->january, $this->treasurer);

    app(InterestEngine::class)->postFor($this->loan->refresh(), $this->february);
});

it('tells the member the day the late-transfer penalty is charged, with the arithmetic', function () {
    app(LoanRepaymentService::class)->record(
        $this->loan,
        Kwacha::of(3_000),
        $this->treasurer,
        Carbon::parse('2026-02-12'),
    );

    Notification::assertSentTo(
        $this->borrower,
        PenaltyApplied::class,
        fn (PenaltyApplied $notification): bool => $notification->daysLate === 3
            && $notification->workingOut() === '3 days late × K100.00 a day = K300.00',
    );
});

it('tells the member when a missed installment costs them ten per cent', function () {
    app(PenaltyService::class)->closeMonth($this->february, $this->treasurer);

    Notification::assertSentTo(
        $this->borrower,
        PenaltyApplied::class,
        fn (PenaltyApplied $notification): bool => str_contains($notification->workingOut(), '10% of')
            && str_contains($notification->workingOut(), 'outstanding = '),
    );
});

it('does not chase a member whose ledgers have been frozen by a payout', function () {
    /*
     * Nothing can be posted against a frozen member, so the penalty here is one that
     * was charged before the freeze — the notifier is what has to stay quiet, not the
     * ledger, which already refuses.
     */
    $transaction = $this->loan->transactions()->latest('id')->first();

    $this->borrower->forceFill(['ledgers_frozen_at' => Carbon::parse('2026-02-01')])->save();

    app(PenaltyNotifier::class)->notify($this->loan->fresh(), $transaction, 3);

    Notification::assertNotSentTo($this->borrower->refresh(), PenaltyApplied::class);
});
