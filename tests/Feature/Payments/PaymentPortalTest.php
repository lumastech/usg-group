<?php

use App\Domain\Cycles\CurrentCycle;
use App\Domain\Cycles\CycleMonthPlanner;
use App\Domain\Declarations\DeclarationService;
use App\Domain\Payments\PayoutDestinationService;
use App\Enums\MemberRole;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Models\Cycle;
use App\Models\Member;
use App\Models\PaymentIntent;
use App\Models\Payout;
use App\Models\PayoutDestination;
use App\Models\User;
use App\Support\Kwacha;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;

/**
 * The payment screens end to end: who may watch the money, who may push it, and what
 * a member can do with their own.
 */
beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->travelTo(Carbon::parse('2026-01-05'));

    $this->gateway = fakeGateway();
    $this->gateway->resolvedName = 'Bertha Phiri';

    $this->cycle = Cycle::factory()->create();
    $this->months = app(CycleMonthPlanner::class)->plan($this->cycle);
    app(CurrentCycle::class)->set($this->cycle);

    $this->december = $this->months->firstWhere('sequence', 1);
    $this->january = $this->months->firstWhere('sequence', 2);

    $this->member = Member::factory()->for($this->cycle)->create([
        'full_name' => 'Bertha Phiri',
        'phone' => '0977433571',
        'joining_fee_paid' => false,
    ]);
});

/** Signs in as a user holding one role, with a member record of their own. */
function actingAsPaymentOffice(MemberRole $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role->value);

    Member::factory()->for(test()->cycle)->create([
        'user_id' => $user->id,
        'full_name' => 'Committee '.$role->value,
    ]);

    test()->actingAs($user);

    return $user;
}

/** Signs in as the member the tests are about. */
function actingAsThatMember(): User
{
    $user = User::factory()->create();
    $user->assignRole(MemberRole::Member->value);

    test()->member->forceFill(['user_id' => $user->id])->save();

    test()->actingAs($user);

    return $user;
}

it('shows the payments screen to the offices that may watch the money', function (MemberRole $role): void {
    actingAsPaymentOffice($role);

    $this->get(route('app.payments.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('app/payments/Index'));
})->with([
    MemberRole::Admin,
    MemberRole::Treasurer,
    MemberRole::ViceTreasurer,
    MemberRole::Chairperson,
    MemberRole::ViceChairperson,
]);

it('keeps an ordinary member out of the committee payments screen', function (): void {
    actingAsThatMember();

    $this->get(route('app.payments.index'))->assertForbidden();
});

it('will not let the chair push money, only watch it', function (): void {
    actingAsPaymentOffice(MemberRole::Chairperson);

    $this->post(route('app.payments.request'), [
        'member_id' => $this->member->id,
        'purpose' => PaymentPurpose::JoiningFee->value,
        'amount_ngwee' => 25_000,
    ])->assertForbidden();
});

it('lets the treasurer ask a member\'s phone for money', function (): void {
    actingAsPaymentOffice(MemberRole::Treasurer);

    $this->post(route('app.payments.request'), [
        'member_id' => $this->member->id,
        'purpose' => PaymentPurpose::JoiningFee->value,
        'amount_ngwee' => 25_000,
        'cycle_month_id' => $this->december->id,
    ])->assertRedirect()->assertSessionHas('success');

    expect(PaymentIntent::count())->toBe(1)
        ->and(PaymentIntent::first()->status)->toBe(PaymentStatus::AwaitingAuthorization);
});

it('refuses a login with no member record rather than failing on the actor', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole(MemberRole::Admin->value);

    $this->actingAs($admin);

    $this->post(route('app.payments.request'), [
        'member_id' => $this->member->id,
        'purpose' => PaymentPurpose::JoiningFee->value,
        'amount_ngwee' => 25_000,
        'cycle_month_id' => $this->december->id,
    ])->assertForbidden();

    expect(PaymentIntent::count())->toBe(0);
});

it('shows the ledger\'s own refusal on the form rather than moving the money', function (): void {
    actingAsPaymentOffice(MemberRole::Treasurer);
    app(DeclarationService::class)->submit(
        $this->member,
        $this->january,
        Kwacha::of(500),
        Kwacha::zero(),
        Kwacha::zero(),
        actor: $this->member,
        at: Carbon::parse('2026-01-02 10:00'),
    );

    $this->post(route('app.payments.request'), [
        'member_id' => $this->member->id,
        'purpose' => PaymentPurpose::SavingsContribution->value,
        'amount_ngwee' => 75_000,
        'cycle_month_id' => $this->january->id,
    ])->assertSessionHasErrors('amount_ngwee');

    expect(PaymentIntent::count())->toBe(0);
});

it('lets a committee member ask the provider again about a payment', function (): void {
    actingAsPaymentOffice(MemberRole::Treasurer);
    $this->gateway->willAnswer(PaymentStatus::Failed);

    $intent = PaymentIntent::factory()->for($this->cycle)->for($this->member)->create([
        'status' => PaymentStatus::AwaitingAuthorization,
    ]);

    $this->post(route('app.payments.refresh', $intent))->assertRedirect();

    expect($intent->refresh()->status)->toBe(PaymentStatus::Failed);
});

it('never retries a payment the provider says succeeded', function (): void {
    actingAsPaymentOffice(MemberRole::Treasurer);

    $intent = PaymentIntent::factory()->for($this->cycle)->for($this->member)->successful()->create();

    $this->post(route('app.payments.retry', $intent))->assertForbidden();
});

it('shows the reconciliation screen and runs it on request', function (): void {
    actingAsPaymentOffice(MemberRole::Treasurer);

    $this->get(route('app.payments.reconciliation'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('app/payments/Reconciliation'));

    $this->post(route('app.payments.reconciliation.store'), ['days' => 1])
        ->assertRedirect()
        ->assertSessionHas('success');
});

it('lets a member pay their own dues from their own phone', function (): void {
    actingAsThatMember();

    $this->get(route('my.payments'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('my/Payments'));

    $this->post(route('my.payments.store'), [
        'purpose' => PaymentPurpose::JoiningFee->value,
        'amount_ngwee' => 25_000,
        'channel' => 'mobile_money',
        'cycle_month_id' => $this->december->id,
    ])->assertRedirect()->assertSessionHas('startedPayment');

    expect(PaymentIntent::count())->toBe(1);
});

it('drafts a card payment without calling the provider, because the widget does that', function (): void {
    actingAsThatMember();

    $this->post(route('my.payments.store'), [
        'purpose' => PaymentPurpose::JoiningFee->value,
        'amount_ngwee' => 25_000,
        'channel' => 'card',
        'cycle_month_id' => $this->december->id,
    ])->assertRedirect();

    expect(PaymentIntent::first()->status)->toBe(PaymentStatus::Draft)
        ->and($this->gateway->collections)->toBeEmpty();
});

it('checks a card payment against the ledger rules before the member ever sees a card form', function (): void {
    actingAsThatMember();

    $this->post(route('my.payments.store'), [
        'purpose' => PaymentPurpose::SavingsContribution->value,
        'amount_ngwee' => 75_000,
        'channel' => 'card',
        'cycle_month_id' => $this->january->id,
    ])->assertSessionHasErrors('amount_ngwee');

    expect(PaymentIntent::count())->toBe(0);
});

it('will not let a member verify somebody else\'s payment', function (): void {
    actingAsThatMember();

    $other = Member::factory()->for($this->cycle)->create();
    $intent = PaymentIntent::factory()->for($this->cycle)->for($other)->create();

    $this->post(route('my.payments.verify', $intent))->assertForbidden();
});

it('lets a member say where their money should go', function (): void {
    actingAsThatMember();

    $this->get(route('my.destinations'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('my/Destinations'));

    $this->post(route('my.destinations.store'), [
        'type' => 'mobile_money',
        'phone' => '0977433571',
    ])->assertRedirect()->assertSessionHas('success');

    expect($this->member->payoutDestinations()->count())->toBe(1)
        ->and($this->member->defaultPayoutDestination()->first()?->resolved_account_name)->toBe('Bertha Phiri');
});

it('will not let one member touch another member\'s destination', function (): void {
    actingAsThatMember();

    $other = Member::factory()->for($this->cycle)->create();
    $destination = PayoutDestination::factory()->for($other)->create();

    $this->delete(route('my.destinations.destroy', $destination))->assertForbidden();
    $this->put(route('my.destinations.default', $destination))->assertForbidden();
});

it('will not let a member wave through a mismatched name on their own account', function (): void {
    $user = actingAsThatMember();
    $user->assignRole(MemberRole::Treasurer->value);

    $this->gateway->resolvedName = 'Somebody Else';
    $destination = app(PayoutDestinationService::class)
        ->addMobileMoney($this->member, '0977433571', null, $this->member);

    $this->post(route('app.payment-destinations.confirm-name', $destination))->assertForbidden();
});

it('needs two signatures before a share-out is sent', function (): void {
    actingAsPaymentOffice(MemberRole::Treasurer);

    $destination = app(PayoutDestinationService::class)
        ->addMobileMoney($this->member, '0977433571', null, $this->member);
    PayoutDestination::query()->whereKey($destination->id)->update(['updated_at' => now()->subWeek()]);

    $payout = Payout::factory()->for($this->cycle)->for($this->member)->create();

    $this->post(route('app.payouts.send', $payout))->assertSessionHasErrors('approver_password');

    expect(PaymentIntent::count())->toBe(0);
});

it('sends a payout once a second committee member signs', function (): void {
    actingAsPaymentOffice(MemberRole::Treasurer);

    $chair = User::factory()->create(['password' => bcrypt('secret-password')]);
    $chair->assignRole(MemberRole::Chairperson->value);
    Member::factory()->for($this->cycle)->create(['user_id' => $chair->id]);

    $destination = app(PayoutDestinationService::class)
        ->addMobileMoney($this->member, '0977433571', null, $this->member);
    PayoutDestination::query()->whereKey($destination->id)->update(['updated_at' => now()->subWeek()]);

    $payout = Payout::factory()->for($this->cycle)->for($this->member)->create();

    $this->post(route('app.payouts.send', $payout), [
        'approver_email' => $chair->email,
        'approver_password' => 'secret-password',
    ])->assertRedirect()->assertSessionHas('success');

    expect(PaymentIntent::where('purpose', PaymentPurpose::Payout->value)->count())->toBe(1);
});

it('shows the share-out payment run to the treasury', function (): void {
    actingAsPaymentOffice(MemberRole::Treasurer);

    $this->get(route('app.shareout.payments'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('app/shareout/Payments'));
});
