<?php

use App\Domain\Cycles\CurrentCycle;
use App\Domain\Cycles\CycleMonthPlanner;
use App\Domain\SocialFund\SocialFundContributions;
use App\Enums\MemberRole;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Models\Cycle;
use App\Models\PaymentIntent;
use App\Models\User;
use App\Support\Kwacha;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;

/**
 * A member paying the K250 from the fund screen itself.
 *
 * The contribution is paid once for the whole cycle, so most of what is tested here is
 * what the screen refuses: a second prompt against a live one, and an amount other than
 * the constitution's.
 */
beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->travelTo(Carbon::parse('2026-01-05 09:00'));

    $this->gateway = fakeGateway();

    $this->cycle = Cycle::factory()->create();
    app(CycleMonthPlanner::class)->plan($this->cycle);
    app(CurrentCycle::class)->set($this->cycle);

    $this->treasurer = memberWithRole($this->cycle, MemberRole::Treasurer);
    $this->member = memberWithRole($this->cycle, MemberRole::Member, ['phone' => '0977433571']);

    $this->expected = Kwacha::toNgwee($this->cycle->social_fund_contribution_ngwee);
});

it('offers the payment to a member who still owes the contribution', function (): void {
    $this->actingAs($this->member->user)
        ->get('/my/fund')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('my/Fund')
            ->where('contribution.paid', false)
            ->where('abilities.pay', true)
            ->where('payment', null));
});

it('asks the member\'s phone for the exact contribution', function (): void {
    $this->actingAs($this->member->user)
        ->post('/my/fund/pay')
        ->assertRedirect()
        ->assertSessionHas('success');

    $intent = PaymentIntent::sole();

    expect($intent->purpose)->toBe(PaymentPurpose::SocialFundContribution)
        ->and($intent->member_id)->toBe($this->member->id)
        ->and(Kwacha::toNgwee($intent->amount_ngwee))->toBe($this->expected)
        ->and($intent->status)->toBe(PaymentStatus::AwaitingAuthorization)
        ->and($this->gateway->collections[0]->phone)->toBe('0977433571');
});

it('shows the standing prompt instead of offering a second one', function (): void {
    $this->actingAs($this->member->user)->post('/my/fund/pay');

    $this->actingAs($this->member->user)
        ->get('/my/fund')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('my/Fund')
            ->where('abilities.pay', false)
            ->where('payment.status', PaymentStatus::AwaitingAuthorization->value));

    /* Two approved prompts would take K500 for a contribution the fund credits once. */
    $this->actingAs($this->member->user)
        ->post('/my/fund/pay')
        ->assertRedirect()
        ->assertSessionHas('error');

    expect(PaymentIntent::count())->toBe(1);
});

it('lets the member try again once the prompt has gone unanswered', function (): void {
    $this->actingAs($this->member->user)->post('/my/fund/pay');

    $this->travel(61)->minutes();

    $this->actingAs($this->member->user)
        ->get('/my/fund')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('abilities.pay', true)
            ->where('payment.has_stalled', true));

    $this->actingAs($this->member->user)
        ->post('/my/fund/pay')
        ->assertSessionHas('success');

    expect(PaymentIntent::count())->toBe(2)
        ->and(PaymentIntent::orderBy('id')->first()->status)->toBe(PaymentStatus::Abandoned);
});

it('drafts a card payment for the provider\'s page rather than sending one', function (): void {
    $this->actingAs($this->member->user)
        ->post('/my/fund/pay', ['channel' => 'card'])
        ->assertRedirect()
        ->assertSessionHas('startedPayment');

    $intent = PaymentIntent::sole();

    expect($intent->status)->toBe(PaymentStatus::Draft)
        ->and(Kwacha::toNgwee($intent->amount_ngwee))->toBe($this->expected)
        ->and($this->gateway->collections)->toBeEmpty();
});

it('refuses a member who has already paid', function (): void {
    app(SocialFundContributions::class)
        ->record($this->member, $this->cycle->social_fund_contribution_ngwee, $this->treasurer);

    $this->actingAs($this->member->user)
        ->get('/my/fund')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('contribution.paid', true)
            ->where('abilities.pay', false));

    $this->actingAs($this->member->user)
        ->post('/my/fund/pay')
        ->assertRedirect()
        ->assertSessionHas('error');

    expect(PaymentIntent::count())->toBe(0);
});

it('keeps a login with no member record off the payment', function (): void {
    $stranger = User::factory()->create();
    $stranger->assignRole(MemberRole::Member->value);

    $this->actingAs($stranger)
        ->post('/my/fund/pay')
        ->assertRedirect()
        ->assertSessionHas('error');

    expect(PaymentIntent::count())->toBe(0);
});
