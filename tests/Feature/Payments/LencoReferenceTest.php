<?php

use App\Domain\Payments\Lenco\LencoReference;
use App\Enums\PaymentPurpose;

it('builds a reference the provider will accept', function (): void {
    $reference = LencoReference::build(PaymentPurpose::SavingsContribution, 412, 1);

    expect($reference)->toBe('usg-sav-00412-1')
        ->and(LencoReference::isValid($reference))->toBeTrue();
});

it('gives each purpose its own code', function (): void {
    expect(LencoReference::build(PaymentPurpose::LoanRepayment, 7))->toBe('usg-rep-00007-1')
        ->and(LencoReference::build(PaymentPurpose::ShareOut, 7))->toBe('usg-sho-00007-1');
});

it('separates a sandbox reference from a live one', function (): void {
    config()->set('payments.reference_prefix', 'usg-sbx');

    expect(LencoReference::build(PaymentPurpose::SavingsContribution, 412))->toBe('usg-sbx-sav-00412-1');
});

it('reads its own reference back apart', function (): void {
    expect(LencoReference::parse('usg-sav-00412-3'))->toBe([
        'prefix' => 'usg',
        'code' => 'sav',
        'intent_id' => 412,
        'attempt' => 3,
    ]);
});

it('does not claim a reference the group did not mint', function (string $reference): void {
    expect(LencoReference::parse($reference))->toBeNull()
        ->and(LencoReference::isOurs($reference))->toBeFalse();
})->with(['ref-1', 'other-sav-00412-1', 'usg-sav-00412', 'usg-sav-x-1', 'usg']);

it('keeps a retry apart from the attempt it replaces', function (): void {
    expect(LencoReference::build(PaymentPurpose::SavingsContribution, 412, 2))
        ->not->toBe(LencoReference::build(PaymentPurpose::SavingsContribution, 412, 1));
});
