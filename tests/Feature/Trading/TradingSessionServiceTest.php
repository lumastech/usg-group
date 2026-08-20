<?php

use App\Domain\Cycles\CycleMonthPlanner;
use App\Domain\Declarations\DeclarationService;
use App\Domain\Loans\LoanApplicationService;
use App\Domain\Savings\SavingsLedger;
use App\Domain\Trading\TradingSessionService;
use App\Enums\DeclarationStatus;
use App\Enums\LoanStatus;
use App\Enums\MemberRole;
use App\Enums\TradingSessionStatus;
use App\Exceptions\TradingSessionClosedException;
use App\Models\Cycle;
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

    $this->sessions = app(TradingSessionService::class);
    $this->declarations = app(DeclarationService::class);
    $this->treasurer = memberWithRole($this->cycle, MemberRole::Treasurer);
    $this->chair = memberWithRole($this->cycle, MemberRole::Chairperson);
    $this->member = Member::factory()->for($this->cycle)->create();

    $this->declare = fn (Member $member, int $saving, int $repayment = 0, int $requested = 0) => $this->declarations->submit(
        $member,
        $this->january,
        Kwacha::of($saving),
        Kwacha::of($repayment),
        Kwacha::of($requested),
        actor: $member,
        at: Carbon::parse('2026-01-02 10:00'),
    );
});

it('opens a session that locks the month\'s declarations', function () {
    $declaration = ($this->declare)($this->member, 500);

    $session = $this->sessions->openFor($this->january);

    expect($session->status)->toBe(TradingSessionStatus::Open)
        ->and($session->scheduled_conclude_date->toDateString())->toBe($this->january->trading_concludes_on->toDateString())
        ->and($declaration->refresh()->status)->toBe(DeclarationStatus::Locked);
});

it('pre-populates an entry per declaration with the cash the member promised', function () {
    ($this->declare)($this->member, 1_000, 500);

    $session = $this->sessions->openFor($this->january);
    $entry = $session->entries()->where('member_id', $this->member->id)->first();

    expect(Kwacha::toNgwee($entry->expected_in_ngwee))->toBe(150_000)
        ->and(Kwacha::toNgwee($entry->savings_portion_ngwee))->toBe(100_000)
        ->and(Kwacha::toNgwee($entry->repayment_portion_ngwee))->toBe(50_000)
        ->and(Kwacha::toNgwee($entry->expected_out_ngwee))->toBe(0);
});

it('pre-populates what the fund owes from the approved loan queue', function () {
    app(SavingsLedger::class)->record($this->member, $this->december, Kwacha::of(5_000), $this->treasurer);

    /* The declaration comes first: it is the intent the committee then approves. */
    ($this->declare)($this->member, 500, 0, 4_000);

    $applications = app(LoanApplicationService::class);
    $loan = $applications->request($this->member, Kwacha::of(4_000), $this->treasurer, Carbon::parse('2026-01-02 11:00'));
    $applications->approve($loan, $this->chair, $this->treasurer);

    $session = $this->sessions->openFor($this->january);
    $entry = $session->entries()->where('member_id', $this->member->id)->first();

    expect(Kwacha::toNgwee($entry->expected_out_ngwee))->toBe(400_000)
        ->and(Kwacha::toNgwee($entry->expected_in_ngwee))->toBe(50_000);
});

it('is idempotent, picking up a declaration captured late', function () {
    ($this->declare)($this->member, 500);
    $session = $this->sessions->openFor($this->january);

    $latecomer = Member::factory()->for($this->cycle)->create();
    $this->declarations->submit(
        $latecomer,
        $this->january,
        Kwacha::of(1_000),
        Kwacha::zero(),
        Kwacha::zero(),
        actor: $this->treasurer,
        onBehalf: true,
        at: Carbon::parse('2026-01-05 09:00'),
    );

    $reopened = $this->sessions->openFor($this->january);

    expect($reopened->id)->toBe($session->id)
        ->and($reopened->entries()->count())->toBe(2)
        ->and(Kwacha::toNgwee(
            $reopened->entries()->where('member_id', $latecomer->id)->first()->expected_in_ngwee
        ))->toBe(100_000);
});

it('computes penalty days against the weekend-adjusted conclude date', function () {
    ($this->declare)($this->member, 500);
    $session = $this->sessions->openFor($this->january);
    $entry = $session->entries()->where('member_id', $this->member->id)->first();

    $this->sessions->markReceived($entry, Kwacha::of(500), Carbon::parse('2026-01-09 11:00'), $this->treasurer);

    /* The 7th of January 2026 is a Wednesday, so nothing moves it. */
    expect($session->scheduled_conclude_date->toDateString())->toBe('2026-01-07')
        ->and($entry->refresh()->penalty_days)->toBe(2);
});

it('charges no penalty days for money that arrives on or before the trading date', function (string $receivedAt) {
    ($this->declare)($this->member, 500);
    $session = $this->sessions->openFor($this->january);
    $entry = $session->entries()->first();

    $this->sessions->markReceived($entry, Kwacha::of(500), Carbon::parse($receivedAt), $this->treasurer);

    expect($entry->refresh()->penalty_days)->toBe(0);
})->with(['2026-01-04 09:00', '2026-01-07 08:00', '2026-01-07 23:30']);

it('does not treat the monday after a weekend seventh as late', function () {
    /* 7 February 2026 is a Saturday, so trading concludes on Monday the 9th. */
    $february = $this->months->firstWhere('sequence', 3);

    $this->declarations->submit(
        $this->member,
        $february,
        Kwacha::of(500),
        Kwacha::zero(),
        Kwacha::zero(),
        actor: $this->member,
        at: Carbon::parse('2026-02-02 10:00'),
    );

    $session = $this->sessions->openFor($february);
    $entry = $session->entries()->first();

    $this->sessions->markReceived($entry, Kwacha::of(500), Carbon::parse('2026-02-09 10:00'), $this->treasurer);

    expect($session->scheduled_conclude_date->toDateString())->toBe('2026-02-09')
        ->and($entry->refresh()->penalty_days)->toBe(0);
});

it('splits a short payment into savings first and the loan second', function () {
    ($this->declare)($this->member, 1_000, 1_000);

    $session = $this->sessions->openFor($this->january);
    $entry = $session->entries()->first();

    $this->sessions->markReceived($entry, Kwacha::of(1_200), Carbon::parse('2026-01-07 10:00'), $this->treasurer);
    $entry->refresh();

    expect(Kwacha::toNgwee($entry->savings_portion_ngwee))->toBe(100_000)
        ->and(Kwacha::toNgwee($entry->repayment_portion_ngwee))->toBe(20_000)
        ->and(Kwacha::toNgwee($entry->variance_ngwee))->toBe(-80_000);
});

it('reports the day\'s cash position', function () {
    ($this->declare)($this->member, 1_000);
    $other = Member::factory()->for($this->cycle)->create();
    ($this->declare)($other, 500);

    $session = $this->sessions->openFor($this->january);
    $entry = $session->entries()->where('member_id', $this->member->id)->first();
    $this->sessions->markReceived($entry, Kwacha::of(1_000), Carbon::parse('2026-01-07 09:00'), $this->treasurer);

    $totals = $this->sessions->totals($session);

    expect($totals['expected_in_ngwee'])->toBe(150_000)
        ->and($totals['actual_in_ngwee'])->toBe(100_000)
        ->and($totals['cash_position_ngwee'])->toBe(100_000)
        ->and($totals['received_count'])->toBe(1)
        ->and($totals['outstanding_count'])->toBe(1);
});

it('refuses to mark anything on a concluded session', function () {
    ($this->declare)($this->member, 500);
    $session = $this->sessions->openFor($this->january);
    $session->forceFill(['status' => TradingSessionStatus::Concluded])->save();

    $this->sessions->markReceived(
        $session->entries()->first(),
        Kwacha::of(500),
        Carbon::parse('2026-01-07 09:00'),
        $this->treasurer,
    );
})->throws(TradingSessionClosedException::class, 'has been concluded');

it('disburses the queued loan when the treasurer confirms it at the table', function () {
    app(SavingsLedger::class)->record($this->member, $this->december, Kwacha::of(5_000), $this->treasurer);

    ($this->declare)($this->member, 500, 0, 4_000);

    $applications = app(LoanApplicationService::class);
    $loan = $applications->request($this->member, Kwacha::of(4_000), $this->treasurer, Carbon::parse('2026-01-02 11:00'));
    $applications->approve($loan, $this->chair, $this->treasurer);

    $session = $this->sessions->openFor($this->january);
    $entry = $session->entries()->where('member_id', $this->member->id)->first();

    $this->sessions->confirmDisbursement($entry, $this->treasurer);

    expect($loan->refresh()->status)->toBe(LoanStatus::Disbursed)
        ->and(Kwacha::toNgwee($entry->refresh()->actual_out_ngwee))->toBe(400_000)
        ->and($entry->disbursed_at)->not->toBeNull();
});
