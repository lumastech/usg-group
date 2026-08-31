<?php

use App\Domain\Cycles\CycleMonthPlanner;
use App\Domain\Loans\LoanApplicationService;
use App\Domain\Savings\SavingsLedger;
use App\Domain\SocialFund\SocialFundContributions;
use App\Domain\Wallets\WalletDisbursements;
use App\Domain\Wallets\WalletRegistry;
use App\Enums\GrantClaimStatus;
use App\Enums\LoanStatus;
use App\Enums\MemberRole;
use App\Enums\WalletTransferPurpose;
use App\Exceptions\DomainRuleException;
use App\Exceptions\InsufficientWalletBalanceException;
use App\Models\Cycle;
use App\Models\FuneralGrantClaim;
use App\Models\Member;
use App\Models\Payout;
use App\Support\Kwacha;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->cycle = Cycle::factory()->create();
    $this->months = app(CycleMonthPlanner::class)->plan($this->cycle);
    $this->december = $this->months->firstWhere('sequence', 1);
    $this->january = $this->months->firstWhere('sequence', 2);

    $this->treasurer = memberWithRole($this->cycle, MemberRole::Treasurer);
    $this->chair = memberWithRole($this->cycle, MemberRole::Chairperson);
    $this->member = memberWithRole($this->cycle);

    $this->disbursements = app(WalletDisbursements::class);
    $this->registry = app(WalletRegistry::class);

    $this->group = $this->registry->group($this->cycle);
    $this->wallet = $this->registry->forMember($this->member, $this->cycle);

    /* What the group already held when wallets were switched on. */
    $this->registry->recordOpeningFloat($this->cycle, Kwacha::of(50_000), $this->treasurer);

    $this->approvedLoan = function (Member $member) {
        app(SavingsLedger::class)->record($member, $this->december, Kwacha::of(5_000), $this->treasurer);
        $applications = app(LoanApplicationService::class);
        $loan = $applications->request($member, Kwacha::of(2_000), $this->treasurer, Carbon::parse('2026-01-02 09:00'));
        $applications->approve($loan, $this->chair, $this->treasurer);

        return $loan->refresh();
    };
});

it('disburses a loan into the member wallet with no gateway involved', function () {
    $loan = ($this->approvedLoan)($this->member);

    $transfer = $this->disbursements->disburseLoan($loan, $this->january, $this->treasurer);

    expect($transfer->purpose)->toBe(WalletTransferPurpose::LoanDisbursement)
        ->and($loan->refresh()->status)->toBe(LoanStatus::Disbursed)
        ->and(Kwacha::toNgwee($this->wallet->balance()))->toBe(200_000)
        ->and(Kwacha::toNgwee($this->group->balance()))->toBe(5_000_000 - 200_000);
});

it('leaves no trace of a loan when the group cannot cover it', function () {
    $poor = Cycle::factory()->create(['name' => '2026–2027']);
    app(CycleMonthPlanner::class)->plan($poor);

    $loan = ($this->approvedLoan)($this->member);

    /* Drain the group wallet by paying out almost everything first. */
    $other = memberWithRole($this->cycle);
    $bigPayout = Payout::factory()->for($this->cycle)->for($other)->create(['amount_ngwee' => 4_900_000]);
    $this->disbursements->payPayout($bigPayout, $this->treasurer, $this->chair);

    expect(fn () => $this->disbursements->disburseLoan($loan, $this->january, $this->treasurer))
        ->toThrow(InsufficientWalletBalanceException::class);

    expect($loan->refresh()->status)->toBe(LoanStatus::Approved)
        ->and($loan->transactions()->count())->toBe(0)
        ->and(Kwacha::toNgwee($this->wallet->balance()))->toBe(0);
});

it('pays a payout into the wallet and stamps it paid, together or not at all', function () {
    $payout = Payout::factory()->for($this->cycle)->for($this->member)->create(['amount_ngwee' => 300_000]);

    $transfer = $this->disbursements->payPayout($payout, $this->treasurer, $this->chair);

    expect($transfer->purpose)->toBe(WalletTransferPurpose::Payout)
        ->and($payout->refresh()->paid_at)->not->toBeNull()
        ->and($payout->paid_method)->toBe('wallet')
        ->and(Kwacha::toNgwee($this->wallet->balance()))->toBe(300_000);
});

it('still needs two signatures to pay a payout', function () {
    $payout = Payout::factory()->for($this->cycle)->for($this->member)->create(['amount_ngwee' => 100_000]);

    $this->disbursements->payPayout($payout, $this->treasurer, $this->treasurer);
})->throws(DomainRuleException::class);

it('will not let the member being paid sign for their own money', function () {
    $payout = Payout::factory()->for($this->cycle)->for($this->member)->create(['amount_ngwee' => 100_000]);

    $this->disbursements->payPayout($payout, $this->treasurer, $this->member);
})->throws(DomainRuleException::class);

it('does not pay a payout twice', function () {
    $payout = Payout::factory()->for($this->cycle)->for($this->member)->create(['amount_ngwee' => 100_000]);

    $this->disbursements->payPayout($payout, $this->treasurer, $this->chair);
    $this->disbursements->payPayout($payout->refresh(), $this->treasurer, $this->chair);
})->throws(DomainRuleException::class, 'already been paid');

it('pays a funeral grant into the wallet and debits the fund, with both signatures', function () {
    foreach ([$this->member, $this->treasurer, $this->chair] as $payer) {
        app(SocialFundContributions::class)->record($payer, Kwacha::of(250), $this->treasurer);
    }

    $claim = FuneralGrantClaim::factory()->for($this->cycle)->for($this->member)->create([
        'status' => GrantClaimStatus::Approved,
        'amount_ngwee' => 50_000,
    ]);

    $transfer = $this->disbursements->payGrant($claim, $this->treasurer, $this->chair);

    expect($transfer->purpose)->toBe(WalletTransferPurpose::FuneralGrant)
        ->and($claim->refresh()->status)->toBe(GrantClaimStatus::Paid)
        ->and(Kwacha::toNgwee($this->wallet->balance()))->toBe(50_000);
});
