<?php

namespace App\Enums;

/**
 * The rail one payment runs on.
 *
 * `Card` never means our servers touched a card. Cards are only ever entered into
 * the provider's hosted widget, which is what keeps the group out of PCI scope —
 * see docs/LENCO-PAYMENTS-PLAN.md §0.
 */
enum PaymentChannel: string
{
    case MobileMoney = 'mobile_money';
    case Card = 'card';
    case BankAccount = 'bank_account';

    /** Whether this channel is one a member may pay in on. */
    public function isCollectable(): bool
    {
        return $this === self::MobileMoney || $this === self::Card;
    }

    /** Whether the group may send money out over this channel. */
    public function isPayable(): bool
    {
        return $this === self::MobileMoney || $this === self::BankAccount;
    }

    /** What the provider's widget calls this channel. */
    public function widgetChannel(): ?string
    {
        return match ($this) {
            self::MobileMoney => 'mobile-money',
            self::Card => 'card',
            self::BankAccount => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::MobileMoney => 'Mobile money',
            self::Card => 'Card',
            self::BankAccount => 'Bank account',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $channel): string => $channel->value, self::cases());
    }
}
