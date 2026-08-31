<?php

use App\Domain\Cycles\CycleMonthPlanner;
use App\Domain\Declarations\DeclarationService;
use App\Domain\Savings\SavingsLedger;
use App\Domain\Trading\TradingSessionService;
use App\Domain\Wallets\TopUpService;
use App\Domain\Wallets\WalletPayments;
use App\Domain\Wallets\WalletRegistry;
use App\Domain\Wallets\WalletTransferService;
use App\Enums\MemberRole;
use App\Enums\SocialFundTransactionType;
use App\Enums\WalletEntryType;
use App\Enums\WalletTransferPurpose;
use App\Exceptions\DomainRuleException;
use App\Exceptions\InsufficientWalletBalanceException;
use App\Exceptions\InvalidSavingsAmountException;
use App\Exceptions\LockdownSavingsCapException;
use App\Models\Cycle;
use App\Models\Member;
use App\Models\SocialFundTransaction;
use App\Models\WalletEntry;
use App\Support\Kwacha;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->cycle = Cycle::factory()->create();
    $this->months = app(CycleMonthPlanner::class)->plan($this->cycle);
    $this->january = $this->months->firstWhere('sequence', 2);
    $this->september = $this->months->firstWhere('sequence', 10);

    $this->treasurer = memberWithRole($this->cycle, MemberRole::Treasurer);
    $this->member = memberWithRole($this->cycle);

    $this->payments = app(WalletPayments::class);
    $this->registry = app(WalletRegistry::class);
    $this->topUps = app(TopUpService::class);

    $this->wallet = $this->registry->forMember($this->member, $this->cycle);
    $this->group = $this->registry->group($this->cycle);

    /* Money the member is holding in their own wallet, however it got there. */
    $this->fund = function (int $kwacha, ?Member $member = null): void {
        $this->topUps->inCash($member ?? $this->member, Kwacha::of($kwacha), $this->treasurer, $this->cycle);
    };

    /* Declared and approved: money only ever moves against a declaration the committee
       has asked for, so that is the state these tests start from. */
    $this->declare = function (Member $member, int $saving = 500, $month = null, string $at = '2026-01-02 10:00') {
        $month ??= $this->january;

        $declaration = app(DeclarationService::class)->submit(
            $member,
            $month,
            Kwacha::of($saving),
            Kwacha::zero(),
            Kwacha::zero(),
            actor: $member,
            at: Carbon::parse($at),
        );

        return app(DeclarationService::class)->approve($declaration, $this->treasurer);
    };

    $this->declareAndOpen = function (Member $member, int $saving = 500, $month = null) {
        ($this->declare)($member, $saving, $month);

        return app(TradingSessionService::class)->openFor($month ?? $this->january);
    };
});

it('moves savings from the member wallet to the group wallet and marks the sheet', function () {
    ($this->fund)(1000);
    ($this->declareAndOpen)($this->member);

    $transfer = $this->payments->paySavings(
        $this->member,
        $this->january,
        Kwacha::of(500),
        $this->member,
        Carbon::parse('2026-01-07 23:50'),
    );

    expect($transfer->purpose)->toBe(WalletTransferPurpose::SavingsContribution)
        ->and(Kwacha::toNgwee($this->wallet->balance()))->toBe(50_000)
        ->and(Kwacha::toNgwee($this->group->balance()))->toBe(50_000)
        ->and($transfer->entries()->acrossCycles()->count())->toBe(2);

    $entry = $transfer->payable->refresh();

    expect((int) $entry->getRawOriginal('actual_in_ngwee'))->toBe(50_000)
        ->and($entry->received_at->toDateString())->toBe('2026-01-07');
});

it('writes exactly two entries that net to nothing', function () {
    ($this->fund)(1000);
    ($this->declareAndOpen)($this->member);

    $this->payments->paySavings($this->member, $this->january, Kwacha::of(500), $this->member);

    $legs = WalletEntry::query()->acrossCycles()->whereNotNull('wallet_transfer_id')->get();

    expect($legs)->toHaveCount(2)
        ->and($legs->sum(fn ($leg) => $leg->getRawOriginal('amount_ngwee')))->toBe(0)
        ->and($legs->pluck('type')->all())
        ->toEqualCanonicalizing([WalletEntryType::Payment, WalletEntryType::Receipt]);
});

it('refuses a contribution that breaks the K500 increment, and moves nothing', function () {
    ($this->fund)(1000);
    ($this->declareAndOpen)($this->member);

    expect(fn () => $this->payments->paySavings($this->member, $this->january, Kwacha::of(750), $this->member))
        ->toThrow(InvalidSavingsAmountException::class);

    /* The refusal costs nothing: the member is still holding all of it. */
    expect(Kwacha::toNgwee($this->wallet->balance()))->toBe(100_000)
        ->and(Kwacha::toNgwee($this->group->balance()))->toBe(0);
});

it('still applies the September cap to a wallet payment', function () {
    ($this->fund)(2000);

    app(SavingsLedger::class)->record($this->member, $this->september, Kwacha::of(500), $this->treasurer);

    $this->payments->paySavings($this->member, $this->september, Kwacha::of(500), $this->member);
})->throws(LockdownSavingsCapException::class);

it('refuses when the trading session is not open, rather than deferring the money', function () {
    ($this->fund)(1000);
    ($this->declare)($this->member);

    expect(fn () => $this->payments->paySavings($this->member, $this->january, Kwacha::of(500), $this->member))
        ->toThrow(DomainRuleException::class, 'is not open');

    expect(Kwacha::toNgwee($this->wallet->balance()))->toBe(100_000);
});

it('refuses a member who never declared, rather than inventing a row for them', function () {
    ($this->fund)(1000);
    ($this->declareAndOpen)($this->treasurer);

    expect(fn () => $this->payments->paySavings($this->member, $this->january, Kwacha::of(500), $this->member))
        ->toThrow(DomainRuleException::class, 'has not declared');

    expect(Kwacha::toNgwee($this->wallet->balance()))->toBe(100_000);
});

it('refuses to pay more than the wallet holds', function () {
    ($this->fund)(200);
    ($this->declareAndOpen)($this->member);

    $this->payments->paySavings($this->member, $this->january, Kwacha::of(500), $this->member);
})->throws(InsufficientWalletBalanceException::class);

it('settles a whole declaration in one movement', function () {
    ($this->fund)(1000);

    $declaration = ($this->declare)($this->member);
    app(TradingSessionService::class)->openFor($this->january);

    $transfer = $this->payments->settleDeclaration($declaration, $this->member);

    expect(Kwacha::toNgwee($transfer->amount_ngwee))->toBe($declaration->expectedInNgwee())
        ->and(Kwacha::toNgwee($this->group->balance()))->toBe(50_000);

    /* A second settlement is refused — the state that used to need a "standing
       payment" guard is now simply a transfer that exists or does not. */
    expect(fn () => $this->payments->settleDeclaration($declaration->refresh(), $this->member))
        ->toThrow(DomainRuleException::class, 'already been paid');
});

it('pays the social fund contribution out of the wallet', function () {
    ($this->fund)(500);

    $transfer = $this->payments->payFundContribution($this->member, Kwacha::of(250), $this->member, $this->cycle);

    expect($transfer->purpose)->toBe(WalletTransferPurpose::SocialFundContribution)
        ->and(Kwacha::toNgwee($this->wallet->balance()))->toBe(25_000)
        ->and(Kwacha::toNgwee($this->group->balance()))->toBe(25_000);

    $posted = SocialFundTransaction::query()->acrossCycles()
        ->where('member_id', $this->member->id)
        ->where('type', SocialFundTransactionType::Contribution->value)
        ->sole();

    expect(Kwacha::toNgwee($posted->amount_ngwee))->toBe(25_000);

    /* The fund takes this once for the whole cycle. */
    expect(fn () => $this->payments->payFundContribution($this->member, Kwacha::of(250), $this->member, $this->cycle))
        ->toThrow(DomainRuleException::class);
});

it('points the member\'s leg at the ledger row the payment produced', function () {
    ($this->fund)(500);

    $transfer = $this->payments->payFundContribution($this->member, Kwacha::of(250), $this->member, $this->cycle);

    $memberLeg = $transfer->entries()->acrossCycles()->where('wallet_id', $this->wallet->id)->sole();
    $groupLeg = $transfer->entries()->acrossCycles()->where('wallet_id', $this->group->id)->sole();

    expect($memberLeg->postedLedger)->toBeInstanceOf(SocialFundTransaction::class)
        ->and($groupLeg->posted_ledger_id)->toBeNull();
});

it('pays the joining fee out of the wallet and marks it paid', function () {
    $joiner = memberWithRole($this->cycle, MemberRole::Member, ['joining_fee_paid' => false]);
    ($this->fund)(2000, $joiner);

    $this->payments->payJoiningFee($joiner, $this->january, Kwacha::of(1000), $this->treasurer);

    expect($joiner->refresh()->joining_fee_paid)->toBeTrue();

    expect(fn () => $this->payments->payJoiningFee($joiner, $this->january, Kwacha::of(1000), $this->treasurer))
        ->toThrow(DomainRuleException::class, 'already paid');
});

it('refuses a transfer from a wallet to itself', function () {
    ($this->fund)(500);

    app(WalletTransferService::class)->transfer(
        from: $this->wallet,
        to: $this->wallet,
        amount: Kwacha::of(100),
        purpose: WalletTransferPurpose::SavingsContribution,
        actor: $this->member,
    );
})->throws(DomainRuleException::class, 'cannot pay itself');
