<?php

use App\Domain\Cycles\CycleMonthPlanner;
use App\Domain\Payments\CollectionInitiator;
use App\Domain\Payments\PaymentIntentService;
use App\Domain\Payments\PaymentPoster;
use App\Domain\Savings\SavingsLedger;
use App\Domain\Wallets\TopUpService;
use App\Domain\Wallets\WalletRegistry;
use App\Enums\MemberRole;
use App\Enums\PaymentChannel;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Enums\TransactionSource;
use App\Enums\WalletEntryType;
use App\Exceptions\DomainRuleException;
use App\Models\Cycle;
use App\Models\PaymentIntent;
use App\Models\WalletEntry;
use App\Support\Kwacha;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->gateway = fakeGateway();

    $this->cycle = Cycle::factory()->create();
    $this->months = app(CycleMonthPlanner::class)->plan($this->cycle);

    $this->treasurer = memberWithRole($this->cycle, MemberRole::Treasurer);
    $this->member = memberWithRole($this->cycle, MemberRole::Member, ['phone' => '0977123456']);

    $this->intents = app(PaymentIntentService::class);
    $this->poster = app(PaymentPoster::class);
    $this->topUps = app(TopUpService::class);
    $this->registry = app(WalletRegistry::class);
    $this->wallet = $this->registry->forMember($this->member, $this->cycle);

    $this->settledTopUp = function (int $ngwee): PaymentIntent {
        $intent = $this->intents->create(
            cycle: $this->cycle,
            purpose: PaymentPurpose::WalletTopUp,
            amountNgwee: $ngwee,
            channel: PaymentChannel::MobileMoney,
            member: $this->member,
        );

        $intent->forceFill([
            'status' => PaymentStatus::Settled,
            'completed_at' => Carbon::parse('2026-01-07 23:50:00'),
        ])->save();

        return $intent->refresh();
    };
});

it('credits the wallet when a top-up settles', function () {
    $intent = ($this->settledTopUp)(75_000);

    expect($this->poster->post($intent))->toBeTrue()
        ->and(Kwacha::toNgwee($this->wallet->balance()))->toBe(75_000);

    $entry = WalletEntry::query()->acrossCycles()->sole();

    expect($entry->type)->toBe(WalletEntryType::TopUp)
        ->and($entry->source)->toBe(TransactionSource::Gateway)
        ->and($entry->payment_intent_id)->toBe($intent->id)
        /* The provider's clock, not ours: a payment made at 23:50 on the 7th is the 7th. */
        ->and($entry->occurred_on->toDateString())->toBe('2026-01-07');
});

it('credits exactly once when the webhook is replayed', function () {
    $intent = ($this->settledTopUp)(50_000);

    $this->poster->post($intent);

    /* A poll arriving behind the webhook re-presents the same settled payment. */
    $intent->forceFill(['status' => PaymentStatus::Settled])->save();
    $this->poster->post($intent->refresh());

    $this->topUps->fromPayment($intent->refresh());

    expect(WalletEntry::query()->acrossCycles()->count())->toBe(1)
        ->and(Kwacha::toNgwee($this->wallet->balance()))->toBe(50_000);
});

it('credits nothing for a top-up that failed', function () {
    $intent = ($this->settledTopUp)(50_000);
    $intent->forceFill(['status' => PaymentStatus::Failed])->save();

    expect($this->poster->post($intent->refresh()))->toBeFalse()
        ->and(WalletEntry::query()->acrossCycles()->count())->toBe(0)
        ->and(Kwacha::toNgwee($this->wallet->balance()))->toBe(0);
});

it('consults no domain rule when asking for a top-up', function () {
    /* K750 is refused as savings — not a multiple of the K500 increment — and this is
       exactly the payment that used to settle at the provider and then be refused by
       the ledger. Into the member's own wallet it is simply money. */
    expect(fn () => app(SavingsLedger::class)->assertValidContribution(
        $this->member,
        $this->months->firstWhere('sequence', 2),
        Kwacha::of(750),
    ))->toThrow(DomainRuleException::class);

    $intent = app(CollectionInitiator::class)->topUp(
        $this->member,
        $this->cycle,
        Kwacha::of(750),
        $this->member,
    );

    expect($intent->purpose)->toBe(PaymentPurpose::WalletTopUp)
        ->and($intent->getRawOriginal('amount_ngwee'))->toBe(75_000)
        ->and($this->gateway->collections)->toHaveCount(1);
});

it('refuses a top-up below what the provider will move', function () {
    app(CollectionInitiator::class)->topUp($this->member, $this->cycle, Kwacha::ofNgwee(50), $this->member);
})->throws(DomainRuleException::class, 'at least');

it('writes a card top-up down without sending anything', function () {
    $intent = app(CollectionInitiator::class)->topUpByCard(
        $this->member,
        $this->cycle,
        Kwacha::of(500),
        $this->member,
    );

    expect($intent->status)->toBe(PaymentStatus::Draft)
        ->and($intent->channel)->toBe(PaymentChannel::Card)
        ->and($this->gateway->collections)->toBeEmpty();
});

it('lets a treasurer top a wallet up with cash counted at the table', function () {
    $entry = $this->topUps->inCash(
        $this->member,
        Kwacha::of(500),
        $this->treasurer,
        $this->cycle,
        Carbon::parse('2026-01-07'),
    );

    expect($entry->source)->toBe(TransactionSource::Cash)
        ->and($entry->recorded_by_member_id)->toBe($this->treasurer->id)
        ->and($entry->payment_intent_id)->toBeNull()
        ->and(Kwacha::toNgwee($this->wallet->balance()))->toBe(50_000);
});

it('opens a wallet for a member who has never had one', function () {
    $fresh = memberWithRole($this->cycle);

    $this->topUps->inCash($fresh, Kwacha::of(500), $this->treasurer, $this->cycle);

    expect(Kwacha::toNgwee($this->registry->forMember($fresh, $this->cycle)->balance()))->toBe(50_000);
});
