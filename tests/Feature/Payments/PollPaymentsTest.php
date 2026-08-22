<?php

use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Exceptions\PaymentGatewayException;
use App\Models\Cycle;
use App\Models\Member;
use App\Models\PaymentIntent;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    $this->gateway = fakeGateway();
    $this->cycle = Cycle::factory()->create();
    $this->member = Member::factory()->for($this->cycle)->create();

    $this->inFlight = fn (PaymentPurpose $purpose, array $attributes = []) => PaymentIntent::factory()
        ->for($this->cycle)
        ->for($this->member)
        ->forPurpose($purpose)
        ->create($attributes + [
            'status' => PaymentStatus::AwaitingAuthorization,
            'initiated_at' => now(),
        ]);
});

it('asks the provider about a payment still in flight', function (): void {
    $intent = ($this->inFlight)(PaymentPurpose::SavingsContribution);
    $this->gateway->willAnswer(PaymentStatus::Successful);

    $this->artisan('unity:poll-payments')->assertSuccessful();

    expect($intent->refresh()->status)->toBe(PaymentStatus::Successful)
        ->and($intent->poll_attempts)->toBe(1);
});

it('does not pester the provider about a payment it just asked about', function (): void {
    ($this->inFlight)(PaymentPurpose::SavingsContribution, ['last_polled_at' => now()]);

    $this->artisan('unity:poll-payments')->assertSuccessful();

    expect(PaymentIntent::first()->poll_attempts)->toBe(0);
});

it('asks anyway when told to', function (): void {
    ($this->inFlight)(PaymentPurpose::SavingsContribution, ['last_polled_at' => now()]);

    $this->artisan('unity:poll-payments', ['--force' => true])->assertSuccessful();

    expect(PaymentIntent::first()->poll_attempts)->toBe(1);
});

it('gives up on a collection nobody ever approved', function (): void {
    $intent = ($this->inFlight)(PaymentPurpose::SavingsContribution, [
        'initiated_at' => Carbon::now()->subHours(3),
        'last_polled_at' => Carbon::now(),
    ]);

    $this->artisan('unity:poll-payments')->assertSuccessful();

    expect($intent->refresh()->status)->toBe(PaymentStatus::Abandoned);
});

it('never abandons a transfer, because money may already have left', function (): void {
    $intent = ($this->inFlight)(PaymentPurpose::LoanDisbursement, [
        'initiated_at' => Carbon::now()->subDays(2),
        'last_polled_at' => Carbon::now(),
        'status' => PaymentStatus::Pending,
    ]);

    $this->artisan('unity:poll-payments')->assertSuccessful();

    expect($intent->refresh()->status)->toBe(PaymentStatus::NeedsAttention)
        ->and($intent->status_reason)->toContain('Lenco dashboard');
});

it('leaves a payment alone while it is still within its window', function (): void {
    $intent = ($this->inFlight)(PaymentPurpose::SavingsContribution, [
        'initiated_at' => Carbon::now()->subMinutes(10),
        'last_polled_at' => Carbon::now(),
    ]);

    $this->artisan('unity:poll-payments')->assertSuccessful();

    expect($intent->refresh()->status)->toBe(PaymentStatus::AwaitingAuthorization);
});

it('carries on when the provider cannot be reached, and counts the attempt', function (): void {
    $intent = ($this->inFlight)(PaymentPurpose::SavingsContribution);
    $this->gateway->throw = new PaymentGatewayException('Could not reach the payment provider.');

    $this->artisan('unity:poll-payments')->assertSuccessful();

    expect($intent->refresh()->status)->toBe(PaymentStatus::AwaitingAuthorization)
        ->and($intent->poll_attempts)->toBe(1)
        ->and($intent->last_polled_at)->not->toBeNull();
});

it('will not ask real wallets for money outside a sandbox', function (): void {
    config()->set('payments.default', 'lenco');
    config()->set('payments.gateways.lenco.base_url', 'https://api.lenco.co/access/v2');
    config()->set('payments.gateways.lenco.api_token', 'test-token');

    $this->artisan('unity:lenco-smoke')->assertFailed();
});

it('will not smoke-test at all when no real gateway is bound', function (): void {
    $this->artisan('unity:lenco-smoke')->assertFailed();
});
