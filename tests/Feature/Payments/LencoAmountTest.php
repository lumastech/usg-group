<?php

use App\Domain\Payments\Lenco\LencoAmount;
use App\Exceptions\PaymentAmountException;

it('writes ngwee as the decimal the provider expects', function (int $ngwee, string $decimal): void {
    expect(LencoAmount::toDecimal($ngwee))->toBe($decimal);
})->with([
    [50_000, '500.00'],
    [1_375, '13.75'],
    [5, '0.05'],
    [0, '0.00'],
    [123_456_789, '1234567.89'],
    [-2_500, '-25.00'],
]);

it('reads the provider decimal back as whole ngwee', function (string $decimal, int $ngwee): void {
    expect(LencoAmount::toNgwee($decimal))->toBe($ngwee);
})->with([
    ['500.00', 50_000],
    ['13.75', 1_375],
    ['0.05', 5],
    ['500', 50_000],
    ['1234567.89', 123_456_789],
    ['-25.00', -2_500],
    ['9.750000', 975],
]);

it('round-trips every amount it is given', function (int $ngwee): void {
    expect(LencoAmount::toNgwee(LencoAmount::toDecimal($ngwee)))->toBe($ngwee);
})->with([1, 99, 100, 49_999, 50_000, 123_456_789]);

it('reads an unquoted number, because the provider does not always quote one', function (): void {
    expect(LencoAmount::toNgwee(13))->toBe(1_300)
        ->and(LencoAmount::toNgwee(13.75))->toBe(1_375);
});

it('treats a missing fee as unknown rather than as nothing', function (): void {
    expect(LencoAmount::toNgweeOrNull(null))->toBeNull()
        ->and(LencoAmount::toNgweeOrNull(''))->toBeNull()
        ->and(LencoAmount::toNgweeOrNull('0.00'))->toBe(0);
});

it('refuses an amount more precise than a ngwee rather than rounding it', function (): void {
    LencoAmount::toNgwee('10.005');
})->throws(PaymentAmountException::class);

it('refuses anything it cannot read as money', function (string $value): void {
    expect(fn (): int => LencoAmount::toNgwee($value))->toThrow(PaymentAmountException::class);
})->with(['', 'K500', '1,000.00', '1e3', 'abc']);
