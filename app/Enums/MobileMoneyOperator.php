<?php

namespace App\Enums;

/**
 * The three Zambian mobile money networks.
 *
 * The values are exactly what the provider expects on the wire, so nothing has to
 * translate them on the way out.
 */
enum MobileMoneyOperator: string
{
    case Airtel = 'airtel';
    case Mtn = 'mtn';
    case Zamtel = 'zamtel';

    public function label(): string
    {
        return match ($this) {
            self::Airtel => 'Airtel Money',
            self::Mtn => 'MTN Mobile Money',
            self::Zamtel => 'Zamtel Kwacha',
        };
    }

    /**
     * The local prefixes each network issues, without the leading zero.
     *
     * @return array<int, string>
     */
    public function prefixes(): array
    {
        return match ($this) {
            self::Airtel => ['97', '77'],
            self::Mtn => ['96', '76'],
            self::Zamtel => ['95', '75'],
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $operator): string => $operator->value, self::cases());
    }
}
