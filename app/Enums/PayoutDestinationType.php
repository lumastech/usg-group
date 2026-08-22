<?php

namespace App\Enums;

/**
 * How a member has asked to be paid.
 *
 * Both are offered on purpose: a member with a bank account is paid into it, and a
 * member whose money lives on their handset is paid there. Neither is the default —
 * the member chooses, and may keep one of each.
 */
enum PayoutDestinationType: string
{
    case BankAccount = 'bank_account';
    case MobileMoney = 'mobile_money';

    public function channel(): PaymentChannel
    {
        return match ($this) {
            self::BankAccount => PaymentChannel::BankAccount,
            self::MobileMoney => PaymentChannel::MobileMoney,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::BankAccount => 'Bank account',
            self::MobileMoney => 'Mobile money',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $type): string => $type->value, self::cases());
    }
}
