<?php

use App\Domain\Cycles\CurrentCycle;
use App\Domain\Cycles\CycleMonthPlanner;
use App\Domain\Payments\PayoutDestinationService;
use App\Domain\Wallets\TopUpService;
use App\Domain\Wallets\WalletRegistry;
use App\Enums\MemberRole;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Enums\WalletEntryType;
use App\Models\Cycle;
use App\Models\Member;
use App\Models\PayoutDestination;
use App\Models\User;
use App\Models\WalletEntry;
use App\Support\Kwacha;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;

/**
 * The wallet screens end to end: what a member can do with their own money, and what
 * the committee may do with everybody's.
 */
beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->travelTo(Carbon::parse('2026-01-05'));

    $this->gateway = fakeGateway();
    $this->gateway->resolvedName = 'Bertha Phiri';

    /* The group's account starts as empty as the wallets do, so the float invariant
       has something exact to be true about. */
    $this->gateway->balanceNgwee = 0;

    $this->cycle = Cycle::factory()->create();
    app(CycleMonthPlanner::class)->plan($this->cycle);
    app(CurrentCycle::class)->set($this->cycle);

    $this->registry = app(WalletRegistry::class);

    $this->memberUser = User::factory()->create();
    $this->memberUser->assignRole(MemberRole::Member->value);
    $this->member = Member::factory()->for($this->cycle)->create([
        'user_id' => $this->memberUser->id,
        'full_name' => 'Bertha Phiri',
        'phone' => '0977433571',
    ]);

    $this->treasurerUser = User::factory()->create();
    $this->treasurerUser->assignRole(MemberRole::Treasurer->value);
    $this->treasurer = Member::factory()->for($this->cycle)->create([
        'user_id' => $this->treasurerUser->id,
        'full_name' => 'Committee treasurer',
    ]);

    $this->chairUser = User::factory()->create();
    $this->chairUser->assignRole(MemberRole::Chairperson->value);
    $this->chair = Member::factory()->for($this->cycle)->create([
        'user_id' => $this->chairUser->id,
        'full_name' => 'Committee chair',
    ]);

    $this->wallet = $this->registry->forMember($this->member, $this->cycle);
});

it('shows a member their own wallet', function (): void {
    app(TopUpService::class)->inCash($this->member, Kwacha::of(500), $this->treasurer, $this->cycle);

    $this->actingAs($this->memberUser)
        ->get(route('my.wallet'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('my/Wallet')
            ->where('wallet.balance_ngwee', 50_000)
            ->has('statement', 1)
            ->where('limits.withdrawal_min_ngwee', 5_000));
});

it('starts a top-up nothing refuses', function (): void {
    /* K750 is refused as savings; into a member's own wallet it is simply money. */
    $this->actingAs($this->memberUser)
        ->post(route('my.wallet.top-up'), [
            'amount_ngwee' => 75_000,
            'channel' => 'mobile_money',
        ])
        ->assertRedirect()
        ->assertSessionHas('startedPayment');

    expect($this->gateway->collections)->toHaveCount(1)
        ->and($this->member->paymentIntents()->sole()->purpose)->toBe(PaymentPurpose::WalletTopUp);
});

it('drafts a card top-up without sending anything', function (): void {
    $this->actingAs($this->memberUser)
        ->post(route('my.wallet.top-up'), [
            'amount_ngwee' => 50_000,
            'channel' => 'card',
        ])
        ->assertRedirect();

    expect($this->gateway->collections)->toBeEmpty()
        ->and($this->member->paymentIntents()->sole()->status)->toBe(PaymentStatus::Draft);
});

it('sends a member their own money when they ask for it', function (): void {
    app(TopUpService::class)->inCash($this->member, Kwacha::of(1000), $this->treasurer, $this->cycle);

    $destination = app(PayoutDestinationService::class)
        ->addMobileMoney($this->member, '0977433571', null, $this->treasurer);
    PayoutDestination::query()->whereKey($destination->id)->update(['updated_at' => now()->subWeek()]);

    $this->actingAs($this->memberUser)
        ->post(route('my.wallet.withdraw'), [
            'amount_ngwee' => 50_000,
            'payout_destination_id' => $destination->id,
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(Kwacha::toNgwee($this->wallet->balance()))->toBe(49_000)
        ->and($this->gateway->transfers)->toHaveCount(1);
});

it('refuses to send a member\'s money to somebody else\'s account', function (): void {
    app(TopUpService::class)->inCash($this->member, Kwacha::of(1000), $this->treasurer, $this->cycle);

    $someoneElse = app(PayoutDestinationService::class)
        ->addMobileMoney($this->treasurer, '0966111222', null, $this->treasurer);

    $this->actingAs($this->memberUser)
        ->post(route('my.wallet.withdraw'), [
            'amount_ngwee' => 50_000,
            'payout_destination_id' => $someoneElse->id,
        ])
        ->assertForbidden();

    expect(Kwacha::toNgwee($this->wallet->balance()))->toBe(100_000);
});

it('shows the committee the float and whether it is really there', function (): void {
    app(TopUpService::class)->inCash($this->member, Kwacha::of(500), $this->treasurer, $this->cycle);

    $this->actingAs($this->treasurerUser)
        ->get(route('app.wallets.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('app/wallets/Index')
            ->where('invariants.member_liability_ngwee', 50_000)
            ->where('invariants.balances', true));
});

it('keeps the float away from a member with no business reading it', function (): void {
    $this->actingAs($this->memberUser)
        ->get(route('app.wallets.index'))
        ->assertForbidden();
});

it('lets a treasurer record cash into a wallet at the table', function (): void {
    $this->actingAs($this->treasurerUser)
        ->post(route('app.wallets.cash-in'), [
            'member_id' => $this->member->id,
            'amount_ngwee' => 50_000,
            'note' => 'Counted at the January table',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(Kwacha::toNgwee($this->wallet->balance()))->toBe(50_000);
});

it('will not pay a wallet out in cash on one signature', function (): void {
    app(TopUpService::class)->inCash($this->member, Kwacha::of(500), $this->treasurer, $this->cycle);

    $this->actingAs($this->treasurerUser)
        ->post(route('app.wallets.cash-out'), [
            'member_id' => $this->member->id,
            'amount_ngwee' => 50_000,
        ])
        ->assertSessionHasErrors(['approver_email', 'approver_password']);

    expect(Kwacha::toNgwee($this->wallet->balance()))->toBe(50_000);
});

it('pays a wallet out in cash on two', function (): void {
    app(TopUpService::class)->inCash($this->member, Kwacha::of(500), $this->treasurer, $this->cycle);

    $this->actingAs($this->treasurerUser)
        ->post(route('app.wallets.cash-out'), [
            'member_id' => $this->member->id,
            'amount_ngwee' => 50_000,
            'approver_email' => $this->chairUser->email,
            'approver_password' => 'password',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(Kwacha::toNgwee($this->wallet->balance()))->toBe(0)
        ->and(WalletEntry::query()->acrossCycles()
            ->where('type', WalletEntryType::Withdrawal->value)
            ->sole()->second_approver_member_id)->toBe($this->chair->id);
});

it('shows one member\'s statement to the committee', function (): void {
    app(TopUpService::class)->inCash($this->member, Kwacha::of(500), $this->treasurer, $this->cycle);

    $this->actingAs($this->treasurerUser)
        ->get(route('app.wallets.show', $this->wallet))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('app/wallets/Show')
            ->has('statement', 1));
});
