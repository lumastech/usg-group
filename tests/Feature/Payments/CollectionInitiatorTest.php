<?php

use App\Domain\Cycles\CycleMonthPlanner;
use App\Domain\Declarations\DeclarationService;
use App\Domain\Loans\LoanApplicationService;
use App\Domain\Loans\LoanDisbursementQueue;
use App\Domain\Payments\CollectionInitiator;
use App\Domain\Savings\SavingsLedger;
use App\Enums\MemberRole;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Exceptions\DeclarationNotApprovedException;
use App\Exceptions\DomainRuleException;
use App\Exceptions\InvalidSavingsAmountException;
use App\Exceptions\InvalidSocialFundContributionException;
use App\Exceptions\LockdownSavingsCapException;
use App\Models\Cycle;
use App\Models\Member;
use App\Support\Kwacha;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->gateway = fakeGateway();

    $this->cycle = Cycle::factory()->create();
    $this->months = app(CycleMonthPlanner::class)->plan($this->cycle);
    $this->december = $this->months->firstWhere('sequence', 1);
    $this->january = $this->months->firstWhere('sequence', 2);
    $this->september = $this->months->firstWhere('sequence', 10);

    $this->treasurer = memberWithRole($this->cycle, MemberRole::Treasurer);
    $this->member = Member::factory()->for($this->cycle)->create([
        'phone' => '0977433571',
        'joining_fee_paid' => false,
    ]);

    $this->initiator = app(CollectionInitiator::class);

    /* Declared and approved: money is only ever collected against a declaration the
       committee has asked for, so that is the state these tests start from. */
    $this->declare = function ($month, int $saving = 500, string $at = '2026-01-02 10:00') {
        $declaration = ($this->declareOnly)($month, $saving, $at);

        return app(DeclarationService::class)->approve($declaration, $this->treasurer);
    };

    $this->declareOnly = fn ($month, int $saving = 500, string $at = '2026-01-02 10:00') => app(DeclarationService::class)
        ->submit(
            $this->member,
            $month,
            Kwacha::of($saving),
            Kwacha::zero(),
            Kwacha::zero(),
            actor: $this->member,
            at: Carbon::parse($at),
        );
});

it('asks the member to approve a savings payment on their handset', function (): void {
    ($this->declare)($this->january);

    $intent = $this->initiator->savings($this->member, $this->january, Kwacha::of(500), $this->member);

    expect($intent->status)->toBe(PaymentStatus::AwaitingAuthorization)
        ->and($intent->purpose)->toBe(PaymentPurpose::SavingsContribution)
        ->and($intent->amount_ngwee->getMinorAmount()->toInt())->toBe(50_000)
        ->and($this->gateway->collections[0]->phone)->toBe('0977433571');
});

it('refuses an amount the savings ledger would refuse, before any money moves', function (): void {
    ($this->declare)($this->january);

    expect(fn () => $this->initiator->savings($this->member, $this->january, Kwacha::of(750), $this->member))
        ->toThrow(InvalidSavingsAmountException::class);

    expect($this->gateway->collections)->toBeEmpty();
});

it('holds an online payment to the September cap, exactly as cash is held', function (): void {
    app(SavingsLedger::class)->record($this->member, $this->september, Kwacha::of(500), $this->treasurer);

    expect(fn () => $this->initiator->savings($this->member, $this->september, Kwacha::of(500), $this->member))
        ->toThrow(LockdownSavingsCapException::class);
});

it('will not take savings from a member who has not declared', function (): void {
    expect(fn () => $this->initiator->savings($this->member, $this->january, Kwacha::of(500), $this->member))
        ->toThrow(DomainRuleException::class, 'has not declared');

    expect($this->gateway->collections)->toBeEmpty();
});

it('will not take savings against a declaration the committee has not approved', function (): void {
    ($this->declareOnly)($this->january);

    expect(fn () => $this->initiator->savings($this->member, $this->january, Kwacha::of(500), $this->member))
        ->toThrow(DeclarationNotApprovedException::class, 'has not been approved yet');

    expect($this->gateway->collections)->toBeEmpty();
});

it('asks for the joining fee once and not twice', function (): void {
    $intent = $this->initiator->joiningFee($this->member, $this->december, Kwacha::of(250), $this->treasurer);

    expect($intent->purpose)->toBe(PaymentPurpose::JoiningFee);

    $this->member->forceFill(['joining_fee_paid' => true])->save();

    expect(fn () => $this->initiator->joiningFee($this->member->refresh(), $this->december, Kwacha::of(250), $this->treasurer))
        ->toThrow(DomainRuleException::class, 'already paid');
});

it('holds a social fund payment to the exact contribution', function (): void {
    expect(fn () => $this->initiator->socialFund($this->member, $this->cycle, Kwacha::of(100), $this->treasurer))
        ->toThrow(InvalidSocialFundContributionException::class);
});

it('takes the social fund contribution in full', function (): void {
    $intent = $this->initiator->socialFund(
        $this->member,
        $this->cycle,
        $this->cycle->social_fund_contribution_ngwee,
        $this->treasurer,
    );

    expect($intent->purpose)->toBe(PaymentPurpose::SocialFundContribution);
});

it('will not ask for a repayment on a loan that is not running', function (): void {
    app(SavingsLedger::class)->record($this->member, $this->december, Kwacha::of(5_000), $this->treasurer);

    $loan = app(LoanApplicationService::class)->request(
        $this->member,
        Kwacha::of(1_000),
        $this->treasurer,
        Carbon::parse('2026-01-02 09:00'),
    );

    expect(fn () => $this->initiator->repayment($loan, Kwacha::of(500), $this->treasurer))
        ->toThrow(DomainRuleException::class, 'has been disbursed');
});

it('asks for a repayment against a running loan', function (): void {
    app(SavingsLedger::class)->record($this->member, $this->december, Kwacha::of(5_000), $this->treasurer);

    $applications = app(LoanApplicationService::class);
    $loan = $applications->request($this->member, Kwacha::of(2_000), $this->treasurer, Carbon::parse('2026-01-02 09:00'));
    $applications->approve($loan, memberWithRole($this->cycle, MemberRole::Chairperson), $this->treasurer);
    app(LoanDisbursementQueue::class)->disburse($loan->refresh(), $this->january, $this->treasurer);

    $intent = $this->initiator->repayment($loan->refresh(), Kwacha::of(1_000), $this->treasurer, $this->january);

    expect($intent->purpose)->toBe(PaymentPurpose::LoanRepayment)
        ->and($intent->payable_id)->toBe($loan->id);
});

it('will not ask a member with no mobile number on record', function (): void {
    $member = Member::factory()->for($this->cycle)->create(['phone' => null, 'joining_fee_paid' => false]);

    expect(fn () => $this->initiator->joiningFee($member, $this->december, Kwacha::of(250), $this->treasurer))
        ->toThrow(DomainRuleException::class, 'no Zambian mobile number');
});

it('asks the number in front of the treasurer, not only the one on file', function (): void {
    $this->initiator->joiningFee(
        $this->member,
        $this->december,
        Kwacha::of(250),
        $this->treasurer,
        phone: '0961111111',
    );

    expect($this->gateway->collections[0]->phone)->toBe('0961111111');
});

it('records who asked for the money', function (): void {
    $intent = $this->initiator->joiningFee($this->member, $this->december, Kwacha::of(250), $this->treasurer);

    expect($intent->requested_by_member_id)->toBe($this->treasurer->id);
});
