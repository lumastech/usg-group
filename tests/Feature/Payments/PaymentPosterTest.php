<?php

use App\Domain\Cycles\CycleMonthPlanner;
use App\Domain\Declarations\DeclarationService;
use App\Domain\Loans\LoanApplicationService;
use App\Domain\Loans\LoanDisbursementQueue;
use App\Domain\Payments\PaymentIntentService;
use App\Domain\Payments\PaymentPoster;
use App\Domain\Payouts\LedgerFreeze;
use App\Domain\Savings\SavingsLedger;
use App\Domain\Trading\TradingSessionService;
use App\Enums\LoanStatus;
use App\Enums\LoanTransactionType;
use App\Enums\MemberRole;
use App\Enums\PaymentChannel;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Enums\SavingsTransactionType;
use App\Enums\SocialFundTransactionType;
use App\Models\Cycle;
use App\Models\Member;
use App\Models\PaymentIntent;
use App\Models\Payout;
use App\Models\SocialFundTransaction;
use App\Support\Kwacha;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    fakeGateway();

    $this->cycle = Cycle::factory()->create();
    $this->months = app(CycleMonthPlanner::class)->plan($this->cycle);
    $this->december = $this->months->firstWhere('sequence', 1);
    $this->january = $this->months->firstWhere('sequence', 2);

    $this->treasurer = memberWithRole($this->cycle, MemberRole::Treasurer);
    $this->chair = memberWithRole($this->cycle, MemberRole::Chairperson);
    $this->member = Member::factory()->for($this->cycle)->create();

    $this->declare = fn (Member $member, int $saving = 500) => app(DeclarationService::class)->submit(
        $member,
        $this->january,
        Kwacha::of($saving),
        Kwacha::zero(),
        Kwacha::zero(),
        actor: $member,
        at: Carbon::parse('2026-01-02 10:00'),
    );

    $this->intents = app(PaymentIntentService::class);
    $this->poster = app(PaymentPoster::class);
    $this->sessions = app(TradingSessionService::class);

    $this->settled = function (PaymentPurpose $purpose, int $ngwee, $payable = null, $month = null, ?Member $member = null): PaymentIntent {
        $intent = $this->intents->create(
            cycle: $this->cycle,
            purpose: $purpose,
            amountNgwee: $ngwee,
            channel: PaymentChannel::MobileMoney,
            member: $member ?? $this->member,
            payable: $payable,
            month: $month,
        );

        $intent->forceFill([
            'status' => PaymentStatus::Settled,
            'completed_at' => Carbon::parse('2026-01-07 23:50:00'),
        ])->save();

        return $intent->refresh();
    };
});

it('puts an online savings payment on the trading sheet, not straight into the ledger', function (): void {
    ($this->declare)($this->member);
    $session = $this->sessions->openFor($this->january);
    $intent = ($this->settled)(PaymentPurpose::SavingsContribution, 50_000, month: $this->january);

    expect($this->poster->post($intent))->toBeTrue();

    $entry = $session->entries()->where('member_id', $this->member->id)->first();

    expect((int) $entry->getRawOriginal('actual_in_ngwee'))->toBe(50_000)
        ->and($entry->received_at)->not->toBeNull()
        ->and($intent->refresh()->status)->toBe(PaymentStatus::Posted)
        ->and($this->member->savingsTransactions()->count())->toBe(0);
});

it('holds a savings payment that arrives before the sheet is open', function (): void {
    $intent = ($this->settled)(PaymentPurpose::SavingsContribution, 50_000, month: $this->january);

    expect($this->poster->post($intent))->toBeFalse()
        ->and($intent->refresh()->status)->toBe(PaymentStatus::Settled);
});

it('takes up a held savings payment the moment a session opens', function (): void {
    ($this->declare)($this->member);
    $intent = ($this->settled)(PaymentPurpose::SavingsContribution, 50_000, month: $this->january);
    $this->poster->post($intent);

    $session = $this->sessions->openFor($this->january);

    expect($this->poster->post($intent->refresh()))->toBeTrue()
        ->and((int) $session->entries()->where('member_id', $this->member->id)->first()->getRawOriginal('actual_in_ngwee'))
        ->toBe(50_000);
});

it('adds a second payment to what the member has already paid that month', function (): void {
    ($this->declare)($this->member, 1_000);
    $session = $this->sessions->openFor($this->january);

    $this->poster->post(($this->settled)(PaymentPurpose::SavingsContribution, 50_000, month: $this->january));
    $this->poster->post(($this->settled)(PaymentPurpose::SavingsContribution, 50_000, month: $this->january));

    expect((int) $session->entries()->where('member_id', $this->member->id)->first()->getRawOriginal('actual_in_ngwee'))
        ->toBe(100_000);
});

it('records a repayment on the day the member paid, not the day we heard about it', function (): void {
    $borrower = Member::factory()->for($this->cycle)->create();
    app(SavingsLedger::class)->record($borrower, $this->december, Kwacha::of(5_000), $this->treasurer);

    $applications = app(LoanApplicationService::class);
    $loan = $applications->request($borrower, Kwacha::of(2_000), $this->treasurer, Carbon::parse('2026-01-02 09:00'));
    $applications->approve($loan, $this->chair, $this->treasurer);
    app(LoanDisbursementQueue::class)->disburse($loan->refresh(), $this->january, $this->treasurer);

    $intent = ($this->settled)(
        PaymentPurpose::LoanRepayment,
        100_000,
        payable: $loan->refresh(),
        month: $this->january,
        member: $borrower,
    );

    Carbon::setTestNow('2026-01-09 08:00:00');
    expect($this->poster->post($intent))->toBeTrue();
    Carbon::setTestNow();

    $repayment = $loan->transactions()->where('type', LoanTransactionType::Repayment->value)->first();

    expect($repayment)->not->toBeNull()
        ->and($repayment->occurred_on->toDateString())->toBe('2026-01-07')
        ->and($loan->transactions()->where('type', LoanTransactionType::LatePenaltyDaily->value)->count())->toBe(0)
        ->and($intent->refresh()->posted_transaction_id)->toBe($repayment->id);
});

it('posts a joining fee straight away and marks it paid', function (): void {
    $intent = ($this->settled)(PaymentPurpose::JoiningFee, 25_000, month: $this->december);

    expect($this->poster->post($intent))->toBeTrue()
        ->and($this->member->refresh()->joining_fee_paid)->toBeTrue()
        ->and($this->member->savingsTransactions()->where('type', SavingsTransactionType::JoiningFee->value)->count())
        ->toBe(1);
});

it('posts a social fund contribution to the fund', function (): void {
    $intent = ($this->settled)(PaymentPurpose::SocialFundContribution, 25_000, month: $this->december);

    expect($this->poster->post($intent))->toBeTrue()
        ->and(SocialFundTransaction::where('type', SocialFundTransactionType::Contribution->value)->count())->toBe(1);
});

it('parks money the ledgers will not take, with the ledger\'s own words', function (): void {
    app(LedgerFreeze::class)->freeze($this->member);

    $intent = ($this->settled)(PaymentPurpose::SocialFundContribution, 25_000, month: $this->december);

    expect($this->poster->post($intent))->toBeFalse();

    $intent->refresh();

    expect($intent->status)->toBe(PaymentStatus::NeedsAttention)
        ->and($intent->status_reason)->not->toBeEmpty()
        ->and(SocialFundTransaction::count())->toBe(0);
});

it('never posts the same money twice, however many times it is told to', function (): void {
    $intent = ($this->settled)(PaymentPurpose::JoiningFee, 25_000, month: $this->december);

    expect($this->poster->post($intent))->toBeTrue()
        ->and($this->poster->post($intent->refresh()))->toBeFalse()
        ->and($this->poster->post($intent->refresh()))->toBeFalse()
        ->and($this->member->savingsTransactions()->count())->toBe(1);
});

it('ignores a payment the provider has not settled', function (): void {
    $intent = $this->intents->create(
        cycle: $this->cycle,
        purpose: PaymentPurpose::JoiningFee,
        amountNgwee: 25_000,
        channel: PaymentChannel::MobileMoney,
        member: $this->member,
        month: $this->december,
    );

    expect($this->poster->post($intent))->toBeFalse()
        ->and($this->member->savingsTransactions()->count())->toBe(0);
});

it('disburses a loan only once the money has actually left', function (): void {
    $borrower = Member::factory()->for($this->cycle)->create();
    app(SavingsLedger::class)->record($borrower, $this->december, Kwacha::of(5_000), $this->treasurer);

    $applications = app(LoanApplicationService::class);
    $loan = $applications->request($borrower, Kwacha::of(2_000), $this->treasurer, Carbon::parse('2026-01-02 09:00'));
    $applications->approve($loan, $this->chair, $this->treasurer);

    $intent = ($this->settled)(
        PaymentPurpose::LoanDisbursement,
        200_000,
        payable: $loan->refresh(),
        month: $this->january,
        member: $borrower,
    );
    $intent->forceFill(['requested_by_member_id' => $this->treasurer->id])->save();

    expect($loan->refresh()->status)->toBe(LoanStatus::Approved)
        ->and($this->poster->post($intent->refresh()))->toBeTrue()
        ->and($loan->refresh()->status)->toBe(LoanStatus::Disbursed)
        ->and($loan->transactions()->where('type', LoanTransactionType::Disbursement->value)->count())->toBe(1);
});

it('records a payout as paid once the transfer confirms', function (): void {
    $payout = Payout::factory()->for($this->cycle)->for($this->member)->create();

    $intent = ($this->settled)(PaymentPurpose::Payout, 1_200_000, payable: $payout);

    expect($this->poster->post($intent))->toBeTrue()
        ->and($payout->refresh()->paid_at?->toDateString())->toBe('2026-01-07')
        ->and($payout->payment_intent_id)->toBe($intent->id)
        ->and($payout->paid_method)->toBe(PaymentChannel::MobileMoney->value);
});

it('parks money from a member with no declaration, rather than inventing a row', function (): void {
    $this->sessions->openFor($this->january);

    $intent = ($this->settled)(PaymentPurpose::SavingsContribution, 50_000, month: $this->january);

    expect($this->poster->post($intent))->toBeFalse()
        ->and($intent->refresh()->status)->toBe(PaymentStatus::NeedsAttention)
        ->and($intent->status_reason)->toContain('no declaration for this month');
});
