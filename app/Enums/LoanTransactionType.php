<?php

namespace App\Enums;

/**
 * A movement on a loan's ledger.
 *
 * Everything except a repayment and a write-off increases what the member owes, which
 * is what lets the running balance be rebuilt from the entries alone.
 */
enum LoanTransactionType: string
{
    case Disbursement = 'disbursement';
    case InterestCharge = 'interest_charge';
    case Repayment = 'repayment';
    case LatePenaltyDaily = 'late_penalty_daily';
    case MissedInstallmentPenalty = 'missed_installment_penalty';
    case WriteOff = 'write_off';

    /** The sign this entry carries when the balance is rebuilt. */
    public function signedFactor(): int
    {
        return match ($this) {
            self::Disbursement, self::InterestCharge,
            self::LatePenaltyDaily, self::MissedInstallmentPenalty => 1,
            self::Repayment, self::WriteOff => -1,
        };
    }

    public function isPenalty(): bool
    {
        return $this === self::LatePenaltyDaily || $this === self::MissedInstallmentPenalty;
    }

    public function label(): string
    {
        return match ($this) {
            self::Disbursement => 'Disbursement',
            self::InterestCharge => 'Interest charge',
            self::Repayment => 'Repayment',
            self::LatePenaltyDaily => 'Daily late penalty',
            self::MissedInstallmentPenalty => 'Missed installment penalty',
            self::WriteOff => 'Write-off',
        };
    }
}
