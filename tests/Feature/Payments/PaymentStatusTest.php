<?php

use App\Enums\PaymentStatus;

it('lets a payment move forward through its life', function (): void {
    expect(PaymentStatus::Draft->canTransitionTo(PaymentStatus::AwaitingAuthorization))->toBeTrue()
        ->and(PaymentStatus::AwaitingAuthorization->canTransitionTo(PaymentStatus::Successful))->toBeTrue()
        ->and(PaymentStatus::Successful->canTransitionTo(PaymentStatus::Settled))->toBeTrue()
        ->and(PaymentStatus::Settled->canTransitionTo(PaymentStatus::Posted))->toBeTrue();
});

it('never lets a posted payment be reopened by a late webhook', function (PaymentStatus $next): void {
    expect(PaymentStatus::Posted->canTransitionTo($next))->toBeFalse();
})->with([
    PaymentStatus::Successful,
    PaymentStatus::Settled,
    PaymentStatus::Failed,
    PaymentStatus::Pending,
    PaymentStatus::NeedsAttention,
]);

it('treats failed and abandoned as the end of the story', function (): void {
    expect(PaymentStatus::Failed->allowedTransitions())->toBe([])
        ->and(PaymentStatus::Abandoned->allowedTransitions())->toBe([])
        ->and(PaymentStatus::Failed->isTerminal())->toBeTrue()
        ->and(PaymentStatus::Abandoned->isTerminal())->toBeTrue();
});

it('does not let a payment go backwards to waiting', function (): void {
    expect(PaymentStatus::Successful->canTransitionTo(PaymentStatus::Pending))->toBeFalse()
        ->and(PaymentStatus::Settled->canTransitionTo(PaymentStatus::AwaitingAuthorization))->toBeFalse();
});

it('lets money the ledger refused be resolved by hand, but not silently retried', function (): void {
    expect(PaymentStatus::NeedsAttention->canTransitionTo(PaymentStatus::Posted))->toBeTrue()
        ->and(PaymentStatus::NeedsAttention->canTransitionTo(PaymentStatus::Successful))->toBeFalse();
});

it('knows which payments are still worth asking the provider about', function (): void {
    $pollable = array_values(array_filter(
        PaymentStatus::cases(),
        fn (PaymentStatus $status): bool => $status->isPollable(),
    ));

    expect($pollable)->toBe([PaymentStatus::Pending, PaymentStatus::AwaitingAuthorization]);
});

it('counts money as having moved only from the provider saying so onward', function (): void {
    expect(PaymentStatus::Successful->hasSucceeded())->toBeTrue()
        ->and(PaymentStatus::Settled->hasSucceeded())->toBeTrue()
        ->and(PaymentStatus::Posted->hasSucceeded())->toBeTrue()
        ->and(PaymentStatus::AwaitingAuthorization->hasSucceeded())->toBeFalse()
        ->and(PaymentStatus::NeedsAttention->hasSucceeded())->toBeFalse();
});

it('says something a member can act on', function (): void {
    expect(PaymentStatus::AwaitingAuthorization->memberLabel())->toBe('Approve the prompt on your phone')
        ->and(PaymentStatus::AwaitingAuthorization->memberLabel())->not->toContain('offline');
});
