<?php

use App\Domain\Cycles\CycleMonthPlanner;
use App\Domain\Payments\PayoutDestinationService;
use App\Domain\Wallets\TopUpService;
use App\Domain\Wallets\WalletLedger;
use App\Domain\Wallets\WalletReconciler;
use App\Domain\Wallets\WalletRegistry;
use App\Domain\Wallets\WithdrawalService;
use App\Enums\MemberRole;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Enums\WalletEntryType;
use App\Models\Cycle;
use App\Models\PaymentIntent;
use App\Models\PaymentReconciliation;
use App\Models\PayoutDestination;
use App\Support\Kwacha;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->gateway = fakeGateway();

    $this->cycle = Cycle::factory()->create();
    app(CycleMonthPlanner::class)->plan($this->cycle);

    $this->treasurer = memberWithRole($this->cycle, MemberRole::Treasurer);
    $this->member = memberWithRole($this->cycle, MemberRole::Member, ['full_name' => 'Chanda Mwansa']);
    $this->gateway->resolvedName = 'Chanda Mwansa';

    $this->reconciler = app(WalletReconciler::class);
    $this->registry = app(WalletRegistry::class);
    $this->wallet = $this->registry->forMember($this->member, $this->cycle);

    /* The provider's account starts empty, like the wallets. */
    $this->gateway->balanceNgwee = 0;
});

it('agrees when every wallet balance is backed by money at the provider', function () {
    $this->gateway->balanceNgwee = 100_000;

    $intent = PaymentIntent::factory()->for($this->cycle)->for($this->member)->create([
        'purpose' => PaymentPurpose::WalletTopUp,
        'amount_ngwee' => 100_000,
        'status' => PaymentStatus::Settled,
    ]);

    app(TopUpService::class)->fromPayment($intent);

    $result = $this->reconciler->check($this->cycle);

    expect($result['balances'])->toBeTrue()
        ->and($result['wallet_variance_ngwee'])->toBe(0)
        ->and($result['member_liability_ngwee'])->toBe(100_000);
});

it('counts a cash top-up against the tin rather than the provider', function () {
    app(TopUpService::class)->inCash($this->member, Kwacha::of(500), $this->treasurer, $this->cycle);

    $result = $this->reconciler->check($this->cycle);

    expect($result['cash_tin_ngwee'])->toBe(50_000)
        ->and($result['balances'])->toBeTrue()
        ->and($result['wallet_variance_ngwee'])->toBe(0);
});

it('raises the alarm when a wallet holds money nothing is behind', function () {
    /* Exactly the fraud this check exists for: a credit with no money behind it. */
    app(WalletLedger::class)->credit(
        $this->wallet,
        Kwacha::of(5_000),
        WalletEntryType::Adjustment,
        $this->treasurer,
    );

    $result = $this->reconciler->check($this->cycle);

    expect($result['balances'])->toBeFalse()
        ->and($result['wallet_variance_ngwee'])->toBe(500_000);
});

it('keeps the sum straight while a withdrawal is in flight', function () {
    app(TopUpService::class)->inCash($this->member, Kwacha::of(1000), $this->treasurer, $this->cycle);

    $destination = app(PayoutDestinationService::class)
        ->addMobileMoney($this->member, '0977433571', null, $this->treasurer);
    PayoutDestination::query()->whereKey($destination->id)->update(['updated_at' => now()->subWeek()]);

    app(WithdrawalService::class)->request($this->member, Kwacha::of(500), $this->member);

    $result = $this->reconciler->check($this->cycle);

    /* The wallet is already K510 lighter; the money has not left the tin/account yet. */
    expect($result['withdrawals_in_flight_ngwee'])->toBe(51_000)
        ->and($result['wallet_total_ngwee'])->toBe(49_000)
        ->and($result['balances'])->toBeTrue();
});

it('reports rather than alarms when the provider cannot be reached', function () {
    $this->gateway->balanceUnavailable = true;

    $result = $this->reconciler->check($this->cycle);

    expect($result['provider_unreachable'])->toBeTrue()
        ->and($result['wallet_variance_ngwee'])->toBeNull()
        ->and($result['balances'])->toBeFalse();
});

it('writes the day\'s figures beside the payment reconciliation', function () {
    app(TopUpService::class)->inCash($this->member, Kwacha::of(500), $this->treasurer, $this->cycle);

    $this->artisan('unity:reconcile-wallets', ['--cycle' => $this->cycle->id])
        ->assertExitCode(0);

    $row = PaymentReconciliation::query()->acrossCycles()->sole();

    expect($row->walletsBalance())->toBeTrue()
        ->and($row->wallet_invariants['cash_tin_ngwee'])->toBe(50_000);
});

it('exits non-zero so a float that does not exist is treated as an incident', function () {
    app(WalletLedger::class)->credit(
        $this->wallet,
        Kwacha::of(5_000),
        WalletEntryType::Adjustment,
        $this->treasurer,
    );

    $this->artisan('unity:reconcile-wallets', ['--cycle' => $this->cycle->id])
        ->expectsOutputToContain('ALARM')
        ->assertExitCode(1);

    expect(PaymentReconciliation::query()->acrossCycles()->sole()->walletsBalance())->toBeFalse();
});
