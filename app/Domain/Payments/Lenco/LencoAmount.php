<?php

namespace App\Domain\Payments\Lenco;

use App\Exceptions\PaymentAmountException;

/**
 * Converts between the group's ngwee integers and the provider's decimal amounts.
 *
 * Lenco quotes money as a decimal — "500.00", "13.75" — while every money column in
 * this application is an integer of ngwee. Both directions are done with string
 * arithmetic. A float would be the one place in the money path where K0.01 could go
 * missing, and it would go missing silently.
 */
final class LencoAmount
{
    /** 50_000 -> "500.00" */
    public static function toDecimal(int $ngwee): string
    {
        $sign = $ngwee < 0 ? '-' : '';
        $absolute = abs($ngwee);

        return sprintf('%s%d.%02d', $sign, intdiv($absolute, 100), $absolute % 100);
    }

    /**
     * "500.00" -> 50_000
     *
     * Accepts what json_decode may hand us — a string, an int, or a float the provider
     * chose to send unquoted — but never guesses. Anything with more precision than a
     * ngwee is refused rather than rounded, because rounding somebody else's money is
     * not ours to do quietly.
     */
    public static function toNgwee(string|int|float $amount): int
    {
        $value = is_string($amount) ? trim($amount) : self::stringify($amount);

        if (! preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $value, $matches)) {
            throw new PaymentAmountException("Cannot read \"{$value}\" as an amount.");
        }

        [, $sign, $whole, $fraction] = $matches + [3 => ''];

        if (mb_strlen($fraction) > 2 && rtrim(mb_substr($fraction, 2), '0') !== '') {
            throw new PaymentAmountException(
                "\"{$value}\" is more precise than a ngwee; nothing here may round it."
            );
        }

        $ngwee = (int) $whole * 100 + (int) mb_substr(str_pad($fraction, 2, '0'), 0, 2);

        return $sign === '-' ? -$ngwee : $ngwee;
    }

    /** Reads an optional field — a fee the provider has not worked out yet is null. */
    public static function toNgweeOrNull(string|int|float|null $amount): ?int
    {
        return $amount === null || $amount === '' ? null : self::toNgwee($amount);
    }

    /** Renders a float without exponent notation, so the parser can read it back. */
    private static function stringify(int|float $amount): string
    {
        return is_int($amount) ? (string) $amount : rtrim(rtrim(sprintf('%.4F', $amount), '0'), '.');
    }
}
