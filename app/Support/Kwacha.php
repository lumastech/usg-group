<?php

namespace App\Support;

use Brick\Money\Money;

/** Convenience constructors and formatting for Zambian Kwacha amounts. */
final class Kwacha
{
    public const CURRENCY = 'ZMW';

    public const NGWEE_PER_KWACHA = 100;

    /** Build from whole or fractional Kwacha, e.g. Kwacha::of(500) === K500.00 */
    public static function of(int|string $kwacha): Money
    {
        return Money::of($kwacha, self::CURRENCY);
    }

    /** Build from a raw ngwee integer, which is how every money column is stored. */
    public static function ofNgwee(int $ngwee): Money
    {
        return Money::ofMinor($ngwee, self::CURRENCY);
    }

    public static function zero(): Money
    {
        return Money::zero(self::CURRENCY);
    }

    public static function toNgwee(Money $money): int
    {
        return $money->getMinorAmount()->toInt();
    }

    /** Renders as "K1,500.00", the format used across statements and exports. */
    public static function format(Money|int $amount): string
    {
        $money = $amount instanceof Money ? $amount : self::ofNgwee($amount);

        return 'K'.number_format($money->getAmount()->toFloat(), 2);
    }
}
