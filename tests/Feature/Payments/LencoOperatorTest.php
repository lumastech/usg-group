<?php

use App\Domain\Payments\Lenco\LencoOperator;
use App\Enums\MobileMoneyOperator;

it('writes a Zambian number the way the provider wants it', function (string $given, string $expected): void {
    expect(LencoOperator::normalisePhone($given))->toBe($expected);
})->with([
    ['0977433571', '0977433571'],
    ['+260977433571', '0977433571'],
    ['260977433571', '0977433571'],
    ['977433571', '0977433571'],
    ['097 743 3571', '0977433571'],
    ['097-743-3571', '0977433571'],
]);

it('refuses a number that is not a Zambian mobile', function (string $given): void {
    expect(LencoOperator::normalisePhone($given))->toBeNull()
        ->and(LencoOperator::isValidPhone($given))->toBeFalse();
})->with(['0211123456', '09774335', '07', '', 'not a number']);

it('works out the network so the treasurer does not have to', function (string $phone, MobileMoneyOperator $operator): void {
    expect(LencoOperator::forPhone($phone))->toBe($operator);
})->with([
    ['0977433571', MobileMoneyOperator::Airtel],
    ['0771234567', MobileMoneyOperator::Airtel],
    ['0961111111', MobileMoneyOperator::Mtn],
    ['0761111111', MobileMoneyOperator::Mtn],
    ['0955555555', MobileMoneyOperator::Zamtel],
    ['0755555555', MobileMoneyOperator::Zamtel],
]);

it('admits when it cannot tell', function (): void {
    expect(LencoOperator::forPhone('0211123456'))->toBeNull();
});
