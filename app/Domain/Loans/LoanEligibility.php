<?php

namespace App\Domain\Loans;

use App\Support\Kwacha;

/**
 * The answer to "may this member borrow this amount?", with its full reasoning.
 *
 * Every failed condition is kept, not just the first, because the request wizard and
 * the member portal both show the member each specific thing standing in their way.
 * The same object is what the eligibility endpoint serialises.
 */
final class LoanEligibility
{
    /**
     * @param  array<int, array{code: string, message: string}>  $reasons
     */
    private function __construct(
        public readonly array $reasons,
        public readonly int $principalNgwee,
        public readonly int $cumulativeSavingsNgwee,
        public readonly int $ceilingNgwee,
        public readonly ?LoanTenor $tenor,
        public readonly ?LoanTenor $earnedTenor,
        public readonly int $monthsAvailable,
        public readonly bool $lockdown,
        public readonly bool $hasOpenLoan,
        public readonly bool $overridden,
    ) {}

    /**
     * @param  array<int, array{code: string, message: string}>  $reasons
     */
    public static function make(
        array $reasons,
        int $principalNgwee,
        int $cumulativeSavingsNgwee,
        int $ceilingNgwee,
        ?LoanTenor $tenor,
        ?LoanTenor $earnedTenor,
        int $monthsAvailable,
        bool $lockdown,
        bool $hasOpenLoan,
        bool $overridden,
    ): self {
        return new self(
            $reasons, $principalNgwee, $cumulativeSavingsNgwee, $ceilingNgwee,
            $tenor, $earnedTenor, $monthsAvailable, $lockdown, $hasOpenLoan, $overridden,
        );
    }

    public function passed(): bool
    {
        return $this->reasons === [];
    }

    public function failed(): bool
    {
        return ! $this->passed();
    }

    /** The tenor had to be shortened to land inside the final repayment deadline. */
    public function isCompressed(): bool
    {
        return $this->tenor !== null
            && $this->earnedTenor !== null
            && $this->tenor->isCompressedFrom($this->earnedTenor);
    }

    public function hasReason(string $code): bool
    {
        return in_array($code, array_column($this->reasons, 'code'), true);
    }

    /** @return array<int, string> */
    public function reasonMessages(): array
    {
        return array_column($this->reasons, 'message');
    }

    public function summary(): string
    {
        return $this->passed()
            ? 'Eligible to borrow '.Kwacha::format($this->principalNgwee).'.'
            : implode(' ', $this->reasonMessages());
    }

    /**
     * The endpoint contract the request wizard and the member portal both read.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'eligible' => $this->passed(),
            'reasons' => $this->reasons,
            'principal_ngwee' => $this->principalNgwee,
            'cumulative_savings_ngwee' => $this->cumulativeSavingsNgwee,
            'ceiling_ngwee' => $this->ceilingNgwee,
            'tenor_months' => $this->tenor?->months,
            'earned_tenor_months' => $this->earnedTenor?->months,
            'compressed' => $this->isCompressed(),
            'months_available' => $this->monthsAvailable,
            'lockdown' => $this->lockdown,
            'has_open_loan' => $this->hasOpenLoan,
            'overridden' => $this->overridden,
        ];
    }
}
