<?php

use App\Domain\Payments\CollectionRequest;
use App\Domain\Payments\PaymentIntentService;
use App\Domain\Payments\TransferRequest;
use App\Enums\FeeBearer;
use App\Enums\MobileMoneyOperator;
use App\Enums\PaymentChannel;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Enums\PayoutDestinationType;
use App\Exceptions\PaymentGatewayException;
use App\Models\Cycle;
use App\Models\Member;
use App\Models\PaymentIntent;

beforeEach(function (): void {
    $this->gateway = fakeGateway();
    $this->cycle = Cycle::factory()->create();
    $this->member = Member::factory()->for($this->cycle)->create();
    $this->service = app(PaymentIntentService::class);
});

function draftCollection(): PaymentIntent
{
    return app(PaymentIntentService::class)->create(
        cycle: test()->cycle,
        purpose: PaymentPurpose::SavingsContribution,
        amountNgwee: 50_000,
        channel: PaymentChannel::MobileMoney,
        member: test()->member,
    );
}

it('writes the payment down before the provider is called', function (): void {
    $intent = draftCollection();

    expect($intent->status)->toBe(PaymentStatus::Draft)
        ->and($intent->reference)->toBe('usg-sav-'.str_pad((string) $intent->id, 5, '0', STR_PAD_LEFT).'-1')
        ->and($intent->direction->isCollection())->toBeTrue();
});

it('makes the member bear the fee on money coming in', function (): void {
    expect(draftCollection()->fee_bearer)->toBe(FeeBearer::Customer);
});

it('never makes a member bear the fee on money going out', function (): void {
    $intent = $this->service->create(
        cycle: $this->cycle,
        purpose: PaymentPurpose::Payout,
        amountNgwee: 1_200_000,
        channel: PaymentChannel::BankAccount,
        member: $this->member,
    );

    expect($intent->fee_bearer)->toBe(FeeBearer::Merchant);
});

it('records what the provider said about a collection', function (): void {
    $intent = $this->service->sendCollection(
        draftCollection(),
        new CollectionRequest(reference: 'ignored', amountNgwee: 50_000, phone: '0977433571'),
    );

    expect($intent->status)->toBe(PaymentStatus::AwaitingAuthorization)
        ->and($intent->provider_id)->not->toBeNull()
        ->and($intent->initiated_at)->not->toBeNull()
        ->and($this->gateway->collections)->toHaveCount(1);
});

it('records a refusal on the payment before raising it', function (): void {
    $intent = draftCollection();
    $this->gateway->throw = new PaymentGatewayException('Insufficient funds', errorCode: '02');

    try {
        $this->service->sendCollection(
            $intent,
            new CollectionRequest(reference: $intent->reference, amountNgwee: 50_000, phone: '0977433571'),
        );
    } catch (PaymentGatewayException) {
        // expected
    }

    expect($intent->refresh()->status)->toBe(PaymentStatus::Failed)
        ->and($intent->status_reason)->toContain('not enough money');
});

it('refuses to send the same payment twice', function (): void {
    $intent = draftCollection();
    $request = new CollectionRequest(reference: $intent->reference, amountNgwee: 50_000, phone: '0977433571');

    $this->service->sendCollection($intent, $request);
    $this->service->sendCollection($intent->refresh(), $request);
})->throws(PaymentGatewayException::class, 'already been sent');

it('ignores news that arrives after the money is already in the ledgers', function (): void {
    $intent = draftCollection();
    $intent->forceFill(['status' => PaymentStatus::Posted])->save();

    $advanced = $this->service->transition($intent, PaymentStatus::Successful);

    expect($advanced)->toBeFalse()
        ->and($intent->refresh()->status)->toBe(PaymentStatus::Posted);
});

it('still records the fee a late webhook carried, even when the status is stale', function (): void {
    $intent = draftCollection();
    $this->service->sendCollection(
        $intent,
        new CollectionRequest(reference: $intent->reference, amountNgwee: 50_000, phone: '0977433571'),
    );

    $this->gateway->willAnswer(PaymentStatus::Successful);
    $this->service->refresh($intent->refresh());

    expect($intent->refresh()->fee_ngwee?->getMinorAmount()->toInt())->toBe(850)
        ->and($intent->completed_at)->not->toBeNull();
});

it('counts every time it asked the provider', function (): void {
    $intent = draftCollection();
    $this->service->sendCollection(
        $intent,
        new CollectionRequest(reference: $intent->reference, amountNgwee: 50_000, phone: '0977433571'),
    );

    $this->service->refresh($intent->refresh());
    $this->service->refresh($intent->refresh());

    expect($intent->refresh()->poll_attempts)->toBe(2)
        ->and($intent->last_polled_at)->not->toBeNull();
});

it('gives a retry its own reference, because the provider refuses a repeat', function (): void {
    $intent = draftCollection();
    $intent->forceFill(['status' => PaymentStatus::Failed])->save();

    $retry = $this->service->retry($intent);

    expect($retry->reference)->not->toBe($intent->reference)
        ->and($retry->attempt)->toBe(2)
        ->and($retry->retry_of_payment_intent_id)->toBe($intent->id)
        ->and($retry->status)->toBe(PaymentStatus::Draft)
        ->and($retry->amount_ngwee->getMinorAmount()->toInt())->toBe(50_000);
});

it('parks money the ledger would not take rather than losing it', function (): void {
    $intent = draftCollection();

    $this->service->markNeedsAttention($intent, 'Savings must be in increments of K500.00.');

    expect($intent->refresh()->status)->toBe(PaymentStatus::NeedsAttention)
        ->and($intent->status_reason)->toBe('Savings must be in increments of K500.00.');
});

it('sends a transfer to where the member asked to be paid', function (): void {
    $intent = $this->service->create(
        cycle: $this->cycle,
        purpose: PaymentPurpose::LoanDisbursement,
        amountNgwee: 500_000,
        channel: PaymentChannel::MobileMoney,
        member: $this->member,
    );

    $this->service->sendTransfer($intent, new TransferRequest(
        reference: $intent->reference,
        amountNgwee: 500_000,
        type: PayoutDestinationType::MobileMoney,
        phone: '0977433571',
        operator: MobileMoneyOperator::Airtel,
    ));

    expect($this->gateway->transfers)->toHaveCount(1)
        ->and($intent->refresh()->status)->toBe(PaymentStatus::Pending);
});
