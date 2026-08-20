<?php

namespace App\Enums;

/**
 * Where a loan sits in its life.
 *
 * Requested → Approved → Disbursed → Repaying → Settled is the happy path. Rejected
 * and Defaulted are the two ways out of it.
 */
enum LoanStatus: string
{
    case Requested = 'requested';
    case Approved = 'approved';
    case Disbursed = 'disbursed';
    case Repaying = 'repaying';
    case Settled = 'settled';
    case Defaulted = 'defaulted';
    case Rejected = 'rejected';

    /**
     * The statuses that count as "the member already has a loan".
     *
     * The constitution allows one loan at a time, so a member in any of these may not
     * request another without a committee member recording a discretion override.
     *
     * @return array<int, self>
     */
    public static function blocking(): array
    {
        return [self::Requested, self::Approved, self::Disbursed, self::Repaying];
    }

    /** Money is out of the fund and still owed. */
    public static function outstanding(): array
    {
        return [self::Disbursed, self::Repaying, self::Defaulted];
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $status): string => $status->value, self::cases());
    }

    public function blocksNewLoan(): bool
    {
        return in_array($this, self::blocking(), true);
    }

    public function isOutstanding(): bool
    {
        return in_array($this, self::outstanding(), true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Requested',
            self::Approved => 'Approved',
            self::Disbursed => 'Disbursed',
            self::Repaying => 'Repaying',
            self::Settled => 'Settled',
            self::Defaulted => 'Defaulted',
            self::Rejected => 'Rejected',
        };
    }
}
