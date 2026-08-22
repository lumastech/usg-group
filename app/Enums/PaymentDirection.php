<?php

namespace App\Enums;

/**
 * Which way the money is travelling.
 *
 * A collection is money coming into the group's account — a member paying their
 * savings, a repayment, the joining fee. A transfer is money leaving it — a loan
 * disbursed, a share-out paid, a funeral grant sent to a next of kin.
 */
enum PaymentDirection: string
{
    case Collection = 'collection';
    case Transfer = 'transfer';

    public function isCollection(): bool
    {
        return $this === self::Collection;
    }

    public function isTransfer(): bool
    {
        return $this === self::Transfer;
    }

    public function label(): string
    {
        return match ($this) {
            self::Collection => 'Money in',
            self::Transfer => 'Money out',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $direction): string => $direction->value, self::cases());
    }
}
