<?php

namespace App\Enums;

/**
 * What one wallet-to-wallet transfer was for.
 *
 * This is the internal twin of PaymentPurpose. Where PaymentPurpose says what the
 * provider was asked to do, this says what the group's books should record — and with
 * wallets that is nearly everything, because the provider is left with only two jobs:
 * putting money into a wallet and taking money out of one.
 *
 * A refusal here costs nothing. No money has moved and the member is still holding it,
 * which is the whole point of the wallet sitting between the provider and the ledgers.
 */
enum WalletTransferPurpose: string
{
    /* Member → group. */
    case SavingsContribution = 'savings_contribution';
    case DeclarationSettlement = 'declaration_settlement';
    case JoiningFee = 'joining_fee';
    case SocialFundContribution = 'social_fund_contribution';
    case LoanRepayment = 'loan_repayment';

    /* Group → member. */
    case LoanDisbursement = 'loan_disbursement';
    case Payout = 'payout';
    case ShareOut = 'share_out';
    case FuneralGrant = 'funeral_grant';
    case UnityBabyGrant = 'unity_baby_grant';
    case DiasporaApportionment = 'diaspora_apportionment';

    /** Whether the money is going from the member to the group. */
    public function isInbound(): bool
    {
        return match ($this) {
            self::SavingsContribution, self::DeclarationSettlement, self::JoiningFee,
            self::SocialFundContribution, self::LoanRepayment => true,
            default => false,
        };
    }

    /** Whether the money is going from the group to the member. */
    public function isOutbound(): bool
    {
        return ! $this->isInbound();
    }

    /**
     * Whether two distinct committee signatures are needed before the transfer runs.
     *
     * Unchanged from PaymentPurpose::requiresSecondApprover(). Moving where the money
     * goes does not move who has to agree to it.
     */
    public function requiresSecondApprover(): bool
    {
        return match ($this) {
            self::Payout, self::ShareOut, self::FuneralGrant,
            self::UnityBabyGrant, self::DiasporaApportionment => true,
            default => false,
        };
    }

    /**
     * The provider purpose this replaces, for reports that still read across both.
     *
     * Every case has one: the wallet did not invent a kind of money, it only changed
     * where the rule is enforced.
     */
    public function paymentPurpose(): PaymentPurpose
    {
        return match ($this) {
            self::SavingsContribution => PaymentPurpose::SavingsContribution,
            self::DeclarationSettlement => PaymentPurpose::DeclarationSettlement,
            self::JoiningFee => PaymentPurpose::JoiningFee,
            self::SocialFundContribution => PaymentPurpose::SocialFundContribution,
            self::LoanRepayment => PaymentPurpose::LoanRepayment,
            self::LoanDisbursement => PaymentPurpose::LoanDisbursement,
            self::Payout => PaymentPurpose::Payout,
            self::ShareOut => PaymentPurpose::ShareOut,
            self::FuneralGrant => PaymentPurpose::FuneralGrant,
            self::UnityBabyGrant => PaymentPurpose::UnityBabyGrant,
            self::DiasporaApportionment => PaymentPurpose::DiasporaApportionment,
        };
    }

    public function label(): string
    {
        return $this->paymentPurpose()->label();
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $purpose): string => $purpose->value, self::cases());
    }

    /** @return array<int, self> */
    public static function inbound(): array
    {
        return array_values(array_filter(self::cases(), fn (self $purpose): bool => $purpose->isInbound()));
    }

    /** @return array<int, self> */
    public static function outbound(): array
    {
        return array_values(array_filter(self::cases(), fn (self $purpose): bool => $purpose->isOutbound()));
    }
}
