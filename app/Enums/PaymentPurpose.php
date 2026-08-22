<?php

namespace App\Enums;

/**
 * What the money is for.
 *
 * The purpose decides which ledger a settled payment lands in and, just as important,
 * whether it lands there at all: a savings contribution paid online does not post on
 * arrival — it marks the trading sheet and waits for the month to be concluded, so the
 * constitution's posting order is never jumped. See docs/LENCO-PAYMENTS-PLAN.md §6.4.
 */
enum PaymentPurpose: string
{
    case SavingsContribution = 'savings_contribution';
    case JoiningFee = 'joining_fee';
    case LoanRepayment = 'loan_repayment';
    case SocialFundContribution = 'social_fund_contribution';

    case LoanDisbursement = 'loan_disbursement';
    case Payout = 'payout';
    case ShareOut = 'share_out';
    case FuneralGrant = 'funeral_grant';
    case UnityBabyGrant = 'unity_baby_grant';
    case DiasporaApportionment = 'diaspora_apportionment';

    public function direction(): PaymentDirection
    {
        return match ($this) {
            self::SavingsContribution, self::JoiningFee,
            self::LoanRepayment, self::SocialFundContribution => PaymentDirection::Collection,
            default => PaymentDirection::Transfer,
        };
    }

    /**
     * Whether a settled payment posts to a ledger the moment we hear about it.
     *
     * Savings alone says no. The trading sheet is the group's worksheet and
     * `TradingConcluder::conclude()` is the only thing that posts a month — a gateway
     * payment marks a row received and takes its turn with the cash.
     */
    public function postsOnSettlement(): bool
    {
        return $this !== self::SavingsContribution;
    }

    /** Whether two committee signatures are needed before the money may be sent. */
    public function requiresSecondApprover(): bool
    {
        return match ($this) {
            self::Payout, self::ShareOut, self::FuneralGrant,
            self::UnityBabyGrant, self::DiasporaApportionment => true,
            default => false,
        };
    }

    /** The short code that goes into a provider reference, e.g. "sav" in usg-sav-412-1. */
    public function referenceCode(): string
    {
        return match ($this) {
            self::SavingsContribution => 'sav',
            self::JoiningFee => 'joi',
            self::LoanRepayment => 'rep',
            self::SocialFundContribution => 'fnd',
            self::LoanDisbursement => 'dis',
            self::Payout => 'pay',
            self::ShareOut => 'sho',
            self::FuneralGrant => 'fun',
            self::UnityBabyGrant => 'bab',
            self::DiasporaApportionment => 'dia',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::SavingsContribution => 'Savings contribution',
            self::JoiningFee => 'Joining fee',
            self::LoanRepayment => 'Loan repayment',
            self::SocialFundContribution => 'Social fund contribution',
            self::LoanDisbursement => 'Loan disbursement',
            self::Payout => 'Payout',
            self::ShareOut => 'Share-out',
            self::FuneralGrant => 'Funeral grant',
            self::UnityBabyGrant => 'Unity baby grant',
            self::DiasporaApportionment => 'Diaspora apportionment',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $purpose): string => $purpose->value, self::cases());
    }

    /** @return array<int, self> */
    public static function collections(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $purpose): bool => $purpose->direction()->isCollection(),
        ));
    }

    /** @return array<int, self> */
    public static function transfers(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $purpose): bool => $purpose->direction()->isTransfer(),
        ));
    }
}
