<?php

namespace App\Enums;

/**
 * The constitution's exhaustive list of grounds for expelling a member.
 *
 * An expulsion is only ever recorded against one of these; the ground is what the
 * payout rules later read to decide what an expelled member forfeits.
 */
enum ExpulsionGround: string
{
    case Dishonesty = 'dishonesty';
    case Theft = 'theft';
    case UnrulyBehavior = 'unruly_behavior';
    case LoanMisconduct = 'loan_misconduct';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $ground): string => $ground->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::Dishonesty => 'Dishonesty',
            self::Theft => 'Theft',
            self::UnrulyBehavior => 'Unruly behaviour',
            self::LoanMisconduct => 'Loan misconduct',
        };
    }
}
