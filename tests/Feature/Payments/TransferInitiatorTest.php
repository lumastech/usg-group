<?php

use App\Domain\Cycles\CycleMonthPlanner;
use App\Domain\Loans\LoanApplicationService;
use App\Domain\Payments\PaymentPoster;
use App\Domain\Payments\PayoutDestinationService;
use App\Domain\Payments\TransferInitiator;
use App\Domain\Savings\SavingsLedger;
use App\Enums\LoanStatus;
use App\Enums\MemberRole;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Enums\PayoutDestinationType;
use App\Exceptions\DomainRuleException;
use App\Models\Cycle;
use App\Models\Member;
use App\Models\PaymentIntent;
use App\Models\Payout;
use App\Models\PayoutDestination;
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

    $this->treasurer = memberWithRole($this->cycle, MemberRole::Treasurer);
    $this->chair = memberWithRole($this->cycle, MemberRole::Chairperson);
    $this->member = Member::factory()->for($this->cycle)->create(['full_name' => 'Chanda Mwansa']);
    $this->gateway->resolvedName = 'Chanda Mwansa';

    $this->transfers = app(TransferInitiator::class);
    $this->destinations = app(PayoutDestinationService::class);

    /* Settled long enough ago that the cooling-off window has passed. */
    $this->settledDestination = function (Member $member): PayoutDestination {
        $destination = $this->destinations->addMobileMoney($member, '0977433571', null, $this->treasurer);
        PayoutDestination::query()->whereKey($destination->id)->update(['updated_at' => now()->subWeek()]);

        return $destination->refresh();
    };

    $this->approvedLoan = function (Member $member) {
        app(SavingsLedger::class)->record($member, $this->december, Kwacha::of(5_000), $this->treasurer);
        $applications = app(LoanApplicationService::class);
        $loan = $applications->request($member, Kwacha::of(2_000), $this->treasurer, Carbon::parse('2026-01-02 09:00'));
        $applications->approve($loan, $this->chair, $this->treasurer);

        return $loan->refresh();
    };
});

it('sends an approved loan to where the member asked to be paid', function (): void {
    ($this->settledDestination)($this->member);
    $loan = ($this->approvedLoan)($this->member);

    $intent = $this->transfers->disburseLoan($loan, $this->january, $this->treasurer);

    expect($intent->purpose)->toBe(PaymentPurpose::LoanDisbursement)
        ->and($intent->status)->toBe(PaymentStatus::Pending)
        ->and($this->gateway->transfers[0]->type)->toBe(PayoutDestinationType::MobileMoney)
        ->and($this->gateway->transfers[0]->phone)->toBe('0977433571');
});

it('leaves the loan approved until the money is confirmed gone', function (): void {
    ($this->settledDestination)($this->member);
    $loan = ($this->approvedLoan)($this->member);

    $this->transfers->disburseLoan($loan, $this->january, $this->treasurer);

    expect($loan->refresh()->status)->toBe(LoanStatus::Approved)
        ->and($loan->transactions()->count())->toBe(0);
});

it('posts nothing at all when the transfer fails', function (): void {
    ($this->settledDestination)($this->member);
    $loan = ($this->approvedLoan)($this->member);

    $this->gateway->transferStatus = PaymentStatus::Failed;
    $this->gateway->reasonForFailure = 'Invalid recipient account';

    $intent = $this->transfers->disburseLoan($loan, $this->january, $this->treasurer);

    expect($intent->refresh()->status)->toBe(PaymentStatus::Failed)
        ->and($loan->refresh()->status)->toBe(LoanStatus::Approved)
        ->and($loan->transactions()->count())->toBe(0)
        ->and(app(PaymentPoster::class)->post($intent))->toBeFalse();
});

it('will not send the same loan twice while one payment is in flight', function (): void {
    ($this->settledDestination)($this->member);
    $loan = ($this->approvedLoan)($this->member);

    $this->transfers->disburseLoan($loan, $this->january, $this->treasurer);

    expect(fn () => $this->transfers->disburseLoan($loan->refresh(), $this->january, $this->treasurer))
        ->toThrow(DomainRuleException::class, 'already has a payment on its way');
});

it('will not send to a member who has said nothing about where their money goes', function (): void {
    $loan = ($this->approvedLoan)($this->member);

    expect(fn () => $this->transfers->disburseLoan($loan, $this->january, $this->treasurer))
        ->toThrow(DomainRuleException::class, 'has not said where to send their money');
});

it('refuses to start when the group\'s account is short', function (): void {
    ($this->settledDestination)($this->member);
    $loan = ($this->approvedLoan)($this->member);
    $this->gateway->balanceNgwee = 100;

    expect(fn () => $this->transfers->disburseLoan($loan, $this->january, $this->treasurer))
        ->toThrow(DomainRuleException::class, 'not enough to send');

    expect($this->gateway->transfers)->toBeEmpty();
});

it('keeps back the configured headroom', function (): void {
    config()->set('payments.transfers.balance_headroom_ngwee', 100_000);
    ($this->settledDestination)($this->member);
    $loan = ($this->approvedLoan)($this->member);
    $this->gateway->balanceNgwee = 250_000;

    expect(fn () => $this->transfers->disburseLoan($loan, $this->january, $this->treasurer))
        ->toThrow(DomainRuleException::class);
});

it('needs a second signature for a destination changed on the eve of a payout', function (): void {
    $this->destinations->addMobileMoney($this->member, '0977433571', null, $this->member);
    $payout = Payout::factory()->for($this->cycle)->for($this->member)->create();

    expect(fn () => $this->transfers->payPayout($payout, $this->treasurer))
        ->toThrow(DomainRuleException::class, 'changed in the last');
});

it('needs a second signature when the account is in a different name', function (): void {
    $this->gateway->resolvedName = 'Gilbert Phiri';
    $destination = $this->destinations->addMobileMoney($this->member, '0977433571', null, $this->member);
    PayoutDestination::query()->whereKey($destination->id)->update(['updated_at' => now()->subWeek()]);

    $payout = Payout::factory()->for($this->cycle)->for($this->member)->create();

    expect(fn () => $this->transfers->payPayout($payout, $this->treasurer))
        ->toThrow(DomainRuleException::class, 'in the name of Gilbert Phiri');
});

it('always needs two signatures on a payout, settled destination or not', function (): void {
    ($this->settledDestination)($this->member);
    $payout = Payout::factory()->for($this->cycle)->for($this->member)->create();

    expect(fn () => $this->transfers->payPayout($payout, $this->treasurer))
        ->toThrow(DomainRuleException::class, 'second committee member');

    $intent = $this->transfers->payPayout($payout, $this->treasurer, $this->chair);

    expect($intent->purpose)->toBe(PaymentPurpose::Payout)
        ->and($intent->second_approver_member_id)->toBe($this->chair->id);
});

it('will not let one person sign twice', function (): void {
    ($this->settledDestination)($this->member);
    $payout = Payout::factory()->for($this->cycle)->for($this->member)->create();

    expect(fn () => $this->transfers->payPayout($payout, $this->treasurer, $this->treasurer))
        ->toThrow(DomainRuleException::class, 'second, different committee member');
});

it('will not let the member being paid sign for their own money', function (): void {
    $member = memberWithRole($this->cycle, MemberRole::Treasurer, ['full_name' => 'Chanda Mwansa']);
    ($this->settledDestination)($member);
    $payout = Payout::factory()->for($this->cycle)->for($member)->create();

    expect(fn () => $this->transfers->payPayout($payout, $this->chair, $member))
        ->toThrow(DomainRuleException::class, 'their own request');
});

it('does not pay a payout that has already been paid', function (): void {
    ($this->settledDestination)($this->member);
    $payout = Payout::factory()->for($this->cycle)->for($this->member)->create(['paid_at' => now()]);

    expect(fn () => $this->transfers->payPayout($payout, $this->treasurer, $this->chair))
        ->toThrow(DomainRuleException::class, 'already been paid');
});

it('records both signatures on the payment for the audit trail', function (): void {
    ($this->settledDestination)($this->member);
    $payout = Payout::factory()->for($this->cycle)->for($this->member)->create();

    $intent = $this->transfers->payPayout($payout, $this->treasurer, $this->chair);

    expect($intent->approved_by_member_id)->toBe($this->treasurer->id)
        ->and($intent->second_approver_member_id)->toBe($this->chair->id)
        ->and(PaymentIntent::find($intent->id)->payout_destination_id)->not->toBeNull();
});
