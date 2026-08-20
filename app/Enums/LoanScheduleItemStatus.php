<?php

namespace App\Enums;

/** How one month of a repayment schedule closed. */
enum LoanScheduleItemStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case PartiallyPaid = 'partially_paid';
    case Missed = 'missed';

    /** A month that closed short attracts the 10% missed-installment penalty. */
    public function attractsPenalty(): bool
    {
        return $this === self::Missed || $this === self::PartiallyPaid;
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Paid => 'Paid',
            self::PartiallyPaid => 'Partially paid',
            self::Missed => 'Missed',
        };
    }
}
