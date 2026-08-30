<?php

use App\Domain\Cycles\CurrentCycle;
use App\Domain\Cycles\CycleMonthPlanner;
use App\Domain\Declarations\DeclarationService;
use App\Domain\Payments\PaymentPoster;
use App\Domain\Trading\TradingSessionService;
use App\Enums\MemberRole;
use App\Enums\PaymentChannel;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Exceptions\PaymentGatewayException;
use App\Models\Cycle;
use App\Models\Declaration;
use App\Models\PaymentIntent;
use App\Support\Kwacha;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;

/**
 * Paying the approved declaration from the screen it was made on.
 *
 * The member never types an amount: the prompt is for the whole of what the committee
 * approved, once, and it lands on the trading sheet as one figure the way cash counted
 * at the table does.
 */
beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->travelTo(Carbon::parse('2026-01-02 10:00'));

    $this->gateway = fakeGateway();

    $this->cycle = Cycle::factory()->create();
    $this->months = app(CycleMonthPlanner::class)->plan($this->cycle);
    app(CurrentCycle::class)->set($this->cycle);

    $this->january = $this->months->firstWhere('sequence', 2);
    $this->declarations = app(DeclarationService::class);

    $this->treasurer = memberWithRole($this->cycle, MemberRole::Treasurer);
    $this->member = memberWithRole($this->cycle, MemberRole::Member, ['phone' => '0977433571']);

    $this->actingAs($this->member->user);

    /* K500 of savings and a K300 installment: K800 the member brings to the table. */
    $this->declaration = $this->declarations->submit(
        $this->member,
        $this->january,
        Kwacha::of(500),
        Kwacha::of(300),
        Kwacha::zero(),
        actor: $this->member,
    );

    $this->approve = fn (): Declaration => $this->declarations->approve($this->declaration, $this->treasurer);
});

it('asks the phone for the whole of what the committee approved', function (): void {
    ($this->approve)();

    $this->post(route('my.declarations.pay'))
        ->assertRedirect()
        ->assertSessionHas('success');

    $intent = PaymentIntent::sole();

    expect($intent->purpose)->toBe(PaymentPurpose::DeclarationSettlement)
        ->and(Kwacha::toNgwee($intent->amount_ngwee))->toBe(80_000)
        ->and($intent->channel)->toBe(PaymentChannel::MobileMoney)
        ->and($intent->status)->toBe(PaymentStatus::AwaitingAuthorization)
        ->and($intent->member_id)->toBe($this->member->id)
        ->and($intent->payable_id)->toBe($this->declaration->id)
        ->and($intent->payable_type)->toBe(Declaration::class);
});

it('will not collect against a declaration the committee has not approved', function (): void {
    $this->post(route('my.declarations.pay'))
        ->assertRedirect()
        ->assertSessionHas('error');

    expect(PaymentIntent::count())->toBe(0);
});

it('never asks twice for the same declaration', function (): void {
    ($this->approve)();

    $this->post(route('my.declarations.pay'))->assertSessionHas('success');
    $this->post(route('my.declarations.pay'))->assertSessionHas('error');

    expect(PaymentIntent::count())->toBe(1);
});

it('asks again once an attempt has failed', function (): void {
    ($this->approve)();

    $this->post(route('my.declarations.pay'));
    PaymentIntent::sole()->forceFill(['status' => PaymentStatus::Failed])->save();

    $this->post(route('my.declarations.pay'))->assertSessionHas('success');

    expect(PaymentIntent::count())->toBe(2);
});

it('does not net a loan the member asked for off what they are prompted for', function (): void {
    $borrower = memberWithRole($this->cycle, MemberRole::Member, ['phone' => '0977433572']);

    Declaration::factory()
        ->for($this->cycle)
        ->for($borrower)
        ->for($this->january, 'cycleMonth')
        ->amounts(saving: 500, requested: 2_000)
        ->approved()
        ->create();

    $this->actingAs($borrower->user)
        ->post(route('my.declarations.pay'))
        ->assertSessionHas('success');

    /* The K2,000 is paid out to them when the loan is disbursed; it is not a discount
       on the K500 of savings they promised. */
    expect(Kwacha::toNgwee(PaymentIntent::sole()->amount_ngwee))->toBe(50_000);
});

it('puts the whole payment on the trading sheet and splits it back', function (): void {
    ($this->approve)();
    $session = app(TradingSessionService::class)->openFor($this->january);

    $this->post(route('my.declarations.pay'));

    $intent = PaymentIntent::sole();
    $intent->forceFill([
        'status' => PaymentStatus::Settled,
        'completed_at' => Carbon::parse('2026-01-07 18:00'),
    ])->save();

    expect(app(PaymentPoster::class)->post($intent->refresh()))->toBeTrue();

    $entry = $session->entries()->where('member_id', $this->member->id)->first();

    expect((int) $entry->getRawOriginal('actual_in_ngwee'))->toBe(80_000)
        ->and((int) $entry->getRawOriginal('savings_portion_ngwee'))->toBe(50_000)
        ->and((int) $entry->getRawOriginal('repayment_portion_ngwee'))->toBe(30_000)
        ->and($intent->refresh()->status)->toBe(PaymentStatus::Posted)
        ->and($this->member->savingsTransactions()->count())->toBe(0);
});

it('offers the payment on the declaration screen, then shows the one in flight', function (): void {
    ($this->approve)();

    $this->get(route('my.declarations'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('my/Declarations')
            ->where('abilities.pay', true)
            ->where('declaration.expected_in_ngwee', 80_000)
            ->where('payment', null)
            ->etc());

    $this->post(route('my.declarations.pay'));

    $this->get(route('my.declarations'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('abilities.pay', false)
            ->where('payment.amount_ngwee', 80_000)
            ->where('payment.purpose', PaymentPurpose::DeclarationSettlement->value)
            ->etc());
});

it('drafts a card payment for the same amount without calling the provider', function (): void {
    ($this->approve)();

    $this->post(route('my.declarations.pay'), ['channel' => 'card'])
        ->assertRedirect()
        ->assertSessionHas('startedPayment');

    $intent = PaymentIntent::sole();

    expect($intent->channel)->toBe(PaymentChannel::Card)
        ->and($intent->status)->toBe(PaymentStatus::Draft)
        ->and(Kwacha::toNgwee($intent->amount_ngwee))->toBe(80_000)
        ->and($intent->purpose)->toBe(PaymentPurpose::DeclarationSettlement)
        ->and($intent->payable_id)->toBe($this->declaration->id)
        /* The widget takes the money, so nothing was asked of the provider here. */
        ->and($this->gateway->collections)->toBeEmpty();
});

it('refuses a card against a declaration the committee has not approved', function (): void {
    $this->post(route('my.declarations.pay'), ['channel' => 'card'])
        ->assertRedirect()
        ->assertSessionHas('error');

    expect(PaymentIntent::count())->toBe(0);
});

it('hands the browser the reference the widget needs', function (): void {
    ($this->approve)();

    $this->post(route('my.declarations.pay'), ['channel' => 'card'])
        ->assertSessionHas('startedPayment', fn (array $started): bool => $started['reference'] === PaymentIntent::sole()->reference
            && $started['amount_ngwee'] === 80_000
            && $started['channel'] === 'card');
});

it('releases a card the member opened and closed, so they can pay another way', function (): void {
    ($this->approve)();

    $this->post(route('my.declarations.pay'), ['channel' => 'card']);

    $intent = PaymentIntent::sole();

    /* The provider has never heard of the reference, because nothing was sent. */
    $this->gateway->throw = new PaymentGatewayException('Not found', httpStatus: 404);

    $this->post(route('my.payments.verify', $intent))
        ->assertRedirect()
        ->assertSessionHas('info');

    expect($intent->refresh()->status)->toBe(PaymentStatus::Abandoned);

    $this->gateway->throw = null;

    /* And the declaration is payable again, rather than locked behind a dead draft. */
    $this->post(route('my.declarations.pay'))->assertSessionHas('success');

    expect(PaymentIntent::count())->toBe(2);
});

/**
 * The prompt is on the member's phone and the provider's answer never arrives. This is
 * the one failure that must not be reported as a failure: the payment is left standing
 * for the poller, because a "try again" here puts a second live prompt on the same
 * handset and takes K800 twice.
 */
it('leaves a prompt that timed out standing rather than calling it failed', function (): void {
    ($this->approve)();

    $this->gateway->throw = new PaymentGatewayException(
        'The payment provider did not answer in time.',
        outcomeUnknown: true,
    );

    $this->post(route('my.declarations.pay'))
        ->assertRedirect()
        ->assertSessionHas('info')
        ->assertSessionMissing('error');

    $intent = PaymentIntent::sole();

    expect($intent->status)->toBe(PaymentStatus::Pending)
        ->and($intent->initiated_at)->not->toBeNull()
        ->and($intent->hasStalled())->toBeFalse();

    /* And the member cannot start a second one against it while it is still live. */
    $this->gateway->throw = null;

    $this->post(route('my.declarations.pay'))->assertSessionHas('error');

    expect(PaymentIntent::count())->toBe(1);
});

/**
 * The provider did answer, and said no. Nothing moved, so the member is freed to try
 * again straight away rather than waiting out the give-up window.
 */
it('closes a prompt the provider actually refused', function (): void {
    ($this->approve)();

    $this->gateway->throw = new PaymentGatewayException('Insufficient funds', errorCode: '02');

    $this->post(route('my.declarations.pay'))->assertSessionHas('error');

    expect(PaymentIntent::sole()->status)->toBe(PaymentStatus::Failed);

    $this->gateway->throw = null;

    $this->post(route('my.declarations.pay'))->assertSessionHas('success');

    expect(PaymentIntent::count())->toBe(2);
});

/**
 * Once the provider can be reached again it is asked what became of the payment it
 * never answered about, and an approved prompt is taken up as normal.
 */
it('takes up a timed-out prompt the member went on to approve', function (): void {
    ($this->approve)();

    $this->gateway->throw = new PaymentGatewayException(
        'The payment provider did not answer in time.',
        outcomeUnknown: true,
    );

    $this->post(route('my.declarations.pay'));

    $intent = PaymentIntent::sole();

    $this->gateway->throw = null;
    $this->gateway->statusAnswer = PaymentStatus::Settled;

    $this->post(route('my.payments.verify', $intent))->assertRedirect();

    expect($intent->refresh()->status)->not->toBe(PaymentStatus::Abandoned)
        ->and($intent->status->hasSucceeded())->toBeTrue();
});

it('keeps a card draft standing when the provider merely could not be reached', function (): void {
    ($this->approve)();

    $this->post(route('my.declarations.pay'), ['channel' => 'card']);

    $intent = PaymentIntent::sole();

    $this->gateway->throw = new PaymentGatewayException('Could not reach the payment provider.');

    $this->post(route('my.payments.verify', $intent))->assertSessionHas('error');

    /* A timeout says nothing about whether the member paid; abandoning here could
       throw away money that did move. */
    expect($intent->refresh()->status)->toBe(PaymentStatus::Draft);
});

/**
 * A handset prompt nobody approves never comes back as a refusal — it simply goes
 * quiet. Something has to declare it dead, or the member is locked out of paying by an
 * attempt that will never conclude, which is exactly where they were left before.
 */
it('offers another attempt once a prompt has gone unanswered', function (): void {
    ($this->approve)();

    $this->post(route('my.declarations.pay'))->assertSessionHas('success');

    $intent = PaymentIntent::sole();
    expect($intent->status)->toBe(PaymentStatus::AwaitingAuthorization);

    $this->travel(2)->hours();

    $this->get(route('my.declarations'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('abilities.pay', true)
            ->where('payment.has_stalled', true)
            ->etc());
});

it('holds the member to one prompt while it is still live', function (): void {
    ($this->approve)();

    $this->post(route('my.declarations.pay'))->assertSessionHas('success');

    $this->travel(10)->minutes();

    $this->get(route('my.declarations'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('abilities.pay', false)
            ->where('payment.has_stalled', false)
            ->etc());

    /* And the second prompt is still refused, so the table cannot be paid twice. */
    $this->post(route('my.declarations.pay'))->assertSessionHas('error');

    expect(PaymentIntent::count())->toBe(1);
});

it('releases the dead prompt when the member starts another', function (): void {
    ($this->approve)();

    $this->post(route('my.declarations.pay'));

    $first = PaymentIntent::sole();

    $this->travel(2)->hours();

    $this->post(route('my.declarations.pay'))->assertSessionHas('success');

    expect($first->refresh()->status)->toBe(PaymentStatus::Abandoned)
        ->and($first->status_reason)->toContain('in time')
        ->and(PaymentIntent::count())->toBe(2)
        ->and(PaymentIntent::latest('id')->first()->status)->toBe(PaymentStatus::AwaitingAuthorization);
});

it('releases a dead prompt when the member checks on it', function (): void {
    ($this->approve)();

    $this->post(route('my.declarations.pay'));

    $intent = PaymentIntent::sole();

    $this->travel(2)->hours();

    $this->post(route('my.payments.verify', $intent))
        ->assertRedirect()
        ->assertSessionHas('info');

    expect($intent->refresh()->status)->toBe(PaymentStatus::Abandoned);
});

/**
 * Asking the provider first is what makes giving up safe: a prompt approved at the last
 * moment is taken up as a payment, never written off because a clock had run out.
 */
it('never gives up on a stale prompt the provider says was paid', function (): void {
    ($this->approve)();

    $this->post(route('my.declarations.pay'));

    $intent = PaymentIntent::sole();

    $this->travel(2)->hours();

    $this->gateway->willAnswer(PaymentStatus::Successful);

    $this->post(route('my.payments.verify', $intent))->assertRedirect();

    expect($intent->refresh()->status)->not->toBe(PaymentStatus::Abandoned)
        ->and($intent->status)->not->toBe(PaymentStatus::AwaitingAuthorization);
});

/**
 * A push that died before the provider was called leaves a Draft with no `initiated_at`.
 * Nothing was sent, so nothing can have moved, and holding the member to the full
 * give-up window for a prompt their phone never received is an hour of nothing.
 */
it('releases a prompt that never reached the provider', function (): void {
    ($this->approve)();

    $intent = PaymentIntent::factory()
        ->for($this->cycle)
        ->for($this->member)
        ->forPurpose(PaymentPurpose::DeclarationSettlement)
        ->create([
            'channel' => PaymentChannel::MobileMoney,
            'status' => PaymentStatus::Draft,
            'initiated_at' => null,
            'created_at' => Carbon::now()->subMinutes(20),
            'payable_type' => Declaration::class,
            'payable_id' => $this->declaration->id,
        ]);

    expect($this->declaration->standingPayment()->is($intent))->toBeTrue();

    $this->post(route('my.declarations.pay'))->assertSessionHas('success');

    expect($intent->refresh()->status)->toBe(PaymentStatus::Abandoned);
});

it('leaves a prompt alone while its request may still be in flight', function (): void {
    ($this->approve)();

    $intent = PaymentIntent::factory()
        ->for($this->cycle)
        ->for($this->member)
        ->forPurpose(PaymentPurpose::DeclarationSettlement)
        ->create([
            'channel' => PaymentChannel::MobileMoney,
            'status' => PaymentStatus::Draft,
            'initiated_at' => null,
            'payable_type' => Declaration::class,
            'payable_id' => $this->declaration->id,
        ]);

    /* A double-tapped button must not abandon an attempt whose call has not returned:
       that is how a member gets charged twice. */
    $this->post(route('my.declarations.pay'))->assertSessionHas('error');

    expect($intent->refresh()->status)->toBe(PaymentStatus::Draft);
});

it('holds a card draft to the full window, because the widget may still be open', function (): void {
    ($this->approve)();

    $this->post(route('my.declarations.pay'), ['channel' => 'card'])
        ->assertSessionHas('startedPayment');

    $intent = PaymentIntent::sole();

    $this->travel(20)->minutes();

    expect($intent->refresh()->hasStalled())->toBeFalse();

    $this->travel(2)->hours();

    expect($intent->refresh()->hasStalled())->toBeTrue();
});
