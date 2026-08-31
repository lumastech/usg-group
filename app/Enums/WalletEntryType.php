<?php

namespace App\Enums;

/**
 * What one movement on a wallet was.
 *
 * The sign lives on the amount, not here: `amount_ngwee` is signed and the balance is
 * a plain SUM of it. The type says where the movement came from, which is what a
 * member reads on their statement and what reconciliation groups by.
 */
enum WalletEntryType: string
{
    /** Money in from the provider, or cash counted by a treasurer. */
    case TopUp = 'top_up';

    /** The member's leg of an internal transfer: money leaving for the group. */
    case Payment = 'payment';

    /** The other leg: money arriving from the group — a payout, a grant, a loan. */
    case Receipt = 'receipt';

    /** Money out to the provider, or cash handed across the table. */
    case Withdrawal = 'withdrawal';

    /** The provider's cut on a withdrawal, borne by the member (config wallets.withdrawals). */
    case Fee = 'fee';

    /** Undoes an earlier entry. Corrections are never edits — see WalletEntry. */
    case Reversal = 'reversal';

    /** Half of the paired move of a balance from one cycle's wallet to the next. */
    case CarryForward = 'carry_forward';

    /** A committee correction that is not undoing one specific entry. */
    case Adjustment = 'adjustment';

    /**
     * Whether this type is one leg of a `wallet_transfers` pair.
     *
     * Everything else is an external leg — money crossing the boundary between the
     * group and the outside world — and carries no counterparty.
     */
    public function isInternal(): bool
    {
        return match ($this) {
            self::Payment, self::Receipt, self::CarryForward => true,
            default => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::TopUp => 'Top-up',
            self::Payment => 'Payment',
            self::Receipt => 'Receipt',
            self::Withdrawal => 'Withdrawal',
            self::Fee => 'Fee',
            self::Reversal => 'Reversal',
            self::CarryForward => 'Carried forward',
            self::Adjustment => 'Adjustment',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $type): string => $type->value, self::cases());
    }
}
