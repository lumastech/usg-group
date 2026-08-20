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
use App\Enums\LoanTransactionType;
use App\Enums\MemberRole;
use App\Events\LatePenaltyCharged;
use App\Models\Cycle;
use App\Support\Kwacha;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
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

    $this->ledger = app(LoanLedger::class);
    $this->penalties = app(PenaltyService::class);
});

it('charges one hundred kwacha for every day a payment is late', function () {
    Event::fake([LatePenaltyCharged::class]);

    /* February's installment falls due on Monday the 9th; this arrives on the 12th. */
    app(LoanRepaymentService::class)->record(
        $this->loan,
        Kwacha::of(3_000),
        $this->treasurer,
        Carbon::parse('2026-02-12'),
    );

    $penalty = $this->loan->transactions()
        ->where('type', LoanTransactionType::LatePenaltyDaily->value)
        ->first();

    expect($penalty)->not->toBeNull()
        ->and($penalty->amount_ngwee->getMinorAmount()->toInt())->toBe(30_000)
        ->and($penalty->notes)->toContain('3 days late');

    Event::assertDispatched(
        LatePenaltyCharged::class,
        fn (LatePenaltyCharged $event): bool => $event->daysLate === 3 && $event->loan->is($this->loan),
    );
});

it('charges nothing when the payment arrives on the trading date itself', function () {
    app(LoanRepaymentService::class)->record(
        $this->loan,
        Kwacha::of(3_000),
        $this->treasurer,
        Carbon::parse('2026-02-09'),
    );

    expect($this->loan->penaltiesChargedNgwee())->toBe(0);
});

it('counts lateness from the adjusted trading date, not the seventh', function () {
    /* The 7th of February 2026 is a Saturday, so the trading date moves to Monday the 9th. */
    expect($this->penalties->daysLate($this->february, Carbon::parse('2026-02-08'), $this->loan))->toBe(0)
        ->and($this->penalties->daysLate($this->february, Carbon::parse('2026-02-10'), $this->loan))->toBe(1);
});

it('adds a ten percent penalty when a month closes only partly paid', function () {
    app(LoanRepaymentService::class)->record(
        $this->loan,
        Kwacha::of(1_000),
        $this->treasurer,
        $this->february->disbursement_on,
    );

    /* K10,000 + K500 interest, less the K1,000 paid, leaves K9,500 outstanding. */
    expect($this->ledger->balanceNgwee($this->loan->refresh()))->toBe(950_000);

    $this->penalties->closeMonth($this->february, $this->treasurer);

    $item = $this->loan->scheduleItems()->where('cycle_month_id', $this->february->id)->first();
    $penalty = $this->loan->transactions()
        ->where('type', LoanTransactionType::MissedInstallmentPenalty->value)
        ->first();

    expect($item->status)->toBe(LoanScheduleItemStatus::PartiallyPaid)
        ->and($penalty->amount_ngwee->getMinorAmount()->toInt())->toBe(95_000)
        ->and($this->ledger->balanceNgwee($this->loan->refresh()))->toBe(1_045_000);
});

it('adds the same ten percent penalty when a month is missed entirely', function () {
    $this->penalties->closeMonth($this->february, $this->treasurer);

    $item = $this->loan->scheduleItems()->where('cycle_month_id', $this->february->id)->first();

    expect($item->status)->toBe(LoanScheduleItemStatus::Missed)
        ->and($this->loan->penaltiesChargedNgwee())->toBe(105_000);
});

it('charges nothing for a month that closed fully paid', function () {
    app(LoanRepaymentService::class)->record(
        $this->loan,
        Kwacha::of(3_000),
        $this->treasurer,
        $this->february->disbursement_on,
    );

    $this->penalties->closeMonth($this->february, $this->treasurer);

    expect($this->loan->penaltiesChargedNgwee())->toBe(0);
});

it('clears penalties before interest and interest before principal', function () {
    $this->penalties->closeMonth($this->february, $this->treasurer);

    /* K105,000 of penalty and K50,000 of interest stand ahead of any principal. */
    expect(app(LoanRepaymentService::class)->allocate($this->loan->refresh(), 200_000))
        ->toBe(['principal' => 45_000, 'interest' => 50_000, 'penalty' => 105_000]);
});
