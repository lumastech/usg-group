<?php

use App\Domain\Loans\LoanTenor;
use App\Exceptions\InvalidLoanAmountException;
use App\Support\Kwacha;

it('gives each principal band the term the constitution fixes for it', function (int $kwacha, int $months) {
    expect(LoanTenor::for(Kwacha::of($kwacha))->months)->toBe($months);
})->with([
    'K1,000 opens the one-month band' => [1_000, 1],
    'K2,000 closes the one-month band' => [2_000, 1],
    'K2,001 opens the two-month band' => [2_001, 2],
    'K5,000 closes the two-month band' => [5_000, 2],
    'K5,001 opens the four-month band' => [5_001, 4],
    'K10,000 closes the four-month band' => [10_000, 4],
    'K10,001 opens the six-month band' => [10_001, 6],
    'K18,000 closes the six-month band' => [18_000, 6],
    'K18,001 opens the eight-month band' => [18_001, 8],
    'K29,999 closes the eight-month band' => [29_999, 8],
    'K30,000 opens the ten-month band' => [30_000, 10],
    'anything larger stays at ten months' => [75_000, 10],
]);

it('keeps a principal just short of a band boundary in the lower band', function () {
    expect(LoanTenor::forNgwee(2_999_950)->months)->toBe(8);
});

it('refuses a principal below the smallest loan the group issues', function (int $kwacha) {
    LoanTenor::for(Kwacha::of($kwacha));
})->with([0, 1, 500, 999])->throws(InvalidLoanAmountException::class, 'smallest loan');

it('splits the principal into equal monthly installments', function () {
    $installments = LoanTenor::for(Kwacha::of(10_000))->principalInstallmentsNgwee();

    expect($installments)->toBe([250_000, 250_000, 250_000, 250_000])
        ->and(array_sum($installments))->toBe(1_000_000);
});

it('lands any rounding remainder on the final installment so the parts sum exactly', function () {
    $installments = LoanTenor::forNgwee(1_000_001)->principalInstallmentsNgwee();

    expect($installments)->toHaveCount(6)
        ->and(array_sum($installments))->toBe(1_000_001)
        ->and($installments[5])->toBe(166_671);
});

it('compresses a term without ever going below a single month', function () {
    $tenor = LoanTenor::for(Kwacha::of(30_000));

    expect($tenor->compressedTo(3)->months)->toBe(3)
        ->and($tenor->compressedTo(0)->months)->toBe(1)
        ->and($tenor->compressedTo(20)->months)->toBe(10)
        ->and($tenor->compressedTo(3)->isCompressedFrom($tenor))->toBeTrue();
});
