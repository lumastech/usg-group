<?php

use App\Domain\Cycles\CycleMonthPlanner;
use App\Domain\Payments\PaymentPoster;
use App\Domain\Payments\PayoutDestinationService;
use App\Domain\Payouts\LedgerFreeze;
use App\Domain\Wallets\TopUpService;
use App\Domain\Wallets\WalletRegistry;
use App\Domain\Wallets\WithdrawalService;
use App\Enums\MemberRole;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Enums\TransactionSource;
use App\Enums\WalletEntryType;
use App\Exceptions\DomainRuleException;
use App\Exceptions\InsufficientWalletBalanceException;
use App\Exceptions\PaymentGatewayException;
use App\Models\Cycle;
use App\Models\Member;
use App\Models\PayoutDestination;
use App\Models\WalletEntry;
use App\Support\Kwacha;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->gateway = fakeGateway();

    $this->cycle = Cycle::factory()->create();
    app(CycleMonthPlanner::class)->plan($this->cycle);

    $this->treasurer = memberWithRole($this->cycle, MemberRole::Treasurer);
    $this->chair = memberWithRole($this->cycle, MemberRole::Chairperson);
    $this->member = memberWithRole($this->cycle, MemberRole::Member, ['full_name' => 'Chanda Mwansa']);
    $this->gateway->resolvedName = 'Chanda Mwansa';

    $this->withdrawals = app(WithdrawalService::class);
    $this->registry = app(WalletRegistry::class);
    $this->wallet = $this->registry->forMember($this->member, $this->cycle);

    app(TopUpService::class)->inCash($this->member, Kwacha::of(1000), $this->treasurer, $this->cycle);

    /* Settled long enough ago that the cooling-off window has passed. */
    $this->destination = function (Member $member): PayoutDestination {
        $destination = app(PayoutDestinationService::class)
            ->addMobileMoney($member, '0977433571', null, $this->treasurer);

        PayoutDestination::query()->whereKey($destination->id)->update(['updated_at' => now()->subWeek()]);

        return $destination->refresh();
    };
});

it('debits the wallet the moment the transfer is started, not when it confirms', function () {
    ($this->destination)($this->member);

    $intent = $this->withdrawals->request($this->member, Kwacha::of(500), $this->member);

    expect($intent->purpose)->toBe(PaymentPurpose::WalletWithdrawal)
        ->and($this->gateway->transfers)->toHaveCount(1)
        /* K1,000 in, less K500 out and the K10 fee reserved for it. */
        ->and(Kwacha::toNgwee($this->wallet->balance()))->toBe(49_000);

    $entries = WalletEntry::query()->acrossCycles()->where('payment_intent_id', $intent->id)->get();

    expect($entries->pluck('type')->all())
        ->toEqualCanonicalizing([WalletEntryType::Withdrawal, WalletEntryType::Fee]);
});

it('will not let one balance be withdrawn twice while a transfer is in flight', function () {
    ($this->destination)($this->member);

    $this->withdrawals->request($this->member, Kwacha::of(900), $this->member);

    $this->withdrawals->request($this->member, Kwacha::of(900), $this->member);
})->throws(InsufficientWalletBalanceException::class);

it('makes the member whole when the provider refuses the transfer', function () {
    ($this->destination)($this->member);
    $this->gateway->throw = new PaymentGatewayException('The account is closed.');

    expect(fn () => $this->withdrawals->request($this->member, Kwacha::of(500), $this->member))
        ->toThrow(PaymentGatewayException::class);

    expect(Kwacha::toNgwee($this->wallet->balance()))->toBe(100_000)
        /* Reversing entries, not deletions: the attempt and its undo both stay on record. */
        ->and(WalletEntry::query()->acrossCycles()->where('type', WalletEntryType::Reversal->value)->count())
        ->toBe(2);
});

it('never puts the money back on its own when the outcome is unknown', function () {
    ($this->destination)($this->member);
    $this->gateway->throw = new PaymentGatewayException('Timed out.', outcomeUnknown: true);

    expect(fn () => $this->withdrawals->request($this->member, Kwacha::of(500), $this->member))
        ->toThrow(PaymentGatewayException::class);

    /* Money may have left the group's account. That is a person's to resolve. */
    expect(Kwacha::toNgwee($this->wallet->balance()))->toBe(49_000)
        ->and(WalletEntry::query()->acrossCycles()->where('type', WalletEntryType::Reversal->value)->count())
        ->toBe(0);
});

it('puts back a withdrawal the poller later finds refused', function () {
    ($this->destination)($this->member);

    $intent = $this->withdrawals->request($this->member, Kwacha::of(500), $this->member);
    $intent->forceFill(['status' => PaymentStatus::Failed, 'status_reason' => 'Account closed'])->save();

    expect($this->withdrawals->reverseFailed())->toBe(1)
        ->and(Kwacha::toNgwee($this->wallet->balance()))->toBe(100_000)
        /* Running the sweep again finds nothing left to do. */
        ->and($this->withdrawals->reverseFailed())->toBe(0);
});

it('puts back a withdrawal that never reached the provider at all', function () {
    ($this->destination)($this->member);

    $intent = $this->withdrawals->request($this->member, Kwacha::of(500), $this->member);

    /* The wallet is debited before the request goes out. A process that died in
       between leaves the member's money debited against a request nothing ever sent. */
    $intent->forceFill([
        'status' => PaymentStatus::Draft,
        'created_at' => now()->subHours(2),
    ])->save();

    expect($this->withdrawals->reverseFailed())->toBe(1)
        ->and(Kwacha::toNgwee($this->wallet->balance()))->toBe(100_000)
        ->and($intent->refresh()->status)->toBe(PaymentStatus::Abandoned);
});

it('leaves a fresh draft alone, in case the call is still in flight', function () {
    ($this->destination)($this->member);

    $intent = $this->withdrawals->request($this->member, Kwacha::of(500), $this->member);
    $intent->forceFill(['status' => PaymentStatus::Draft])->save();

    expect($this->withdrawals->reverseFailed())->toBe(0)
        ->and(Kwacha::toNgwee($this->wallet->balance()))->toBe(49_000);
});

it('squares the reserved fee up against what the provider actually charged', function () {
    ($this->destination)($this->member);

    $intent = $this->withdrawals->request($this->member, Kwacha::of(500), $this->member);

    /* Reserved K10, charged K8.50: the K1.50 goes back to the member. */
    $intent->forceFill(['status' => PaymentStatus::Settled, 'fee_ngwee' => 850])->save();

    app(PaymentPoster::class)->post($intent->refresh());

    expect(Kwacha::toNgwee($this->wallet->balance()))->toBe(49_150)
        ->and(WalletEntry::query()->acrossCycles()->where('type', WalletEntryType::Adjustment->value)->count())
        ->toBe(1);
});

it('refuses a withdrawal below the floor the committee set', function () {
    ($this->destination)($this->member);

    $this->withdrawals->request($this->member, Kwacha::of(10), $this->member);
})->throws(DomainRuleException::class, 'at least');

it('will not send anywhere the member has not named', function () {
    $this->withdrawals->request($this->member, Kwacha::of(500), $this->member);
})->throws(DomainRuleException::class, 'has not said where');

it('needs a second signature for a destination changed inside the cooling-off window', function () {
    app(PayoutDestinationService::class)->addMobileMoney($this->member, '0977433571', null, $this->treasurer);

    expect(fn () => $this->withdrawals->request($this->member, Kwacha::of(500), $this->member))
        ->toThrow(DomainRuleException::class, 'second committee member');

    /* With one, it goes. */
    $intent = $this->withdrawals->request(
        $this->member,
        Kwacha::of(500),
        $this->treasurer,
        secondApprover: $this->chair,
    );

    expect($intent->second_approver_member_id)->toBe($this->chair->id);
});

it('lets a member whose ledgers are frozen withdraw what they were paid', function () {
    ($this->destination)($this->member);
    app(LedgerFreeze::class)->freeze($this->member);

    $intent = $this->withdrawals->request($this->member->refresh(), Kwacha::of(500), $this->member);

    expect($intent->purpose)->toBe(PaymentPurpose::WalletWithdrawal)
        ->and(Kwacha::toNgwee($this->wallet->balance()))->toBe(49_000);
});

it('pays a wallet out in cash behind two signatures, whatever the amount', function () {
    expect(fn () => $this->withdrawals->payCash($this->member, Kwacha::of(100), $this->treasurer))
        ->toThrow(DomainRuleException::class, 'second committee member');

    $entry = $this->withdrawals->payCash(
        $this->member,
        Kwacha::of(100),
        $this->treasurer,
        secondApprover: $this->chair,
    );

    expect($entry->source)->toBe(TransactionSource::Cash)
        ->and($entry->second_approver_member_id)->toBe($this->chair->id)
        ->and(Kwacha::toNgwee($this->wallet->balance()))->toBe(90_000);
});
