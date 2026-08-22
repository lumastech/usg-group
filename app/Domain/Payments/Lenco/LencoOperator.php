<?php

namespace App\Domain\Payments\Lenco;

use App\Enums\MobileMoneyOperator;

/**
 * Works out which network a Zambian number is on, and writes it the way the provider
 * expects.
 *
 * The treasurer at the trading table should not have to know whether 0966… is MTN.
 * The guess is always overridable — numbers do get ported — but it is right often
 * enough that nobody is asked in the ordinary case.
 */
final class LencoOperator
{
    /** Zambian numbers are ten digits beginning 09, in the form the provider wants. */
    public static function normalisePhone(string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';

        $local = match (true) {
            str_starts_with($digits, '260') => '0'.mb_substr($digits, 3),
            str_starts_with($digits, '0') => $digits,
            mb_strlen($digits) === 9 => '0'.$digits,
            default => $digits,
        };

        return preg_match('/^0[79]\d{8}$/', $local) === 1 ? $local : null;
    }

    public static function forPhone(string $phone): ?MobileMoneyOperator
    {
        $local = self::normalisePhone($phone);

        if ($local === null) {
            return null;
        }

        $prefix = mb_substr($local, 1, 2);

        foreach (MobileMoneyOperator::cases() as $operator) {
            if (in_array($prefix, $operator->prefixes(), true)) {
                return $operator;
            }
        }

        return null;
    }

    public static function isValidPhone(string $phone): bool
    {
        return self::normalisePhone($phone) !== null;
    }
}
