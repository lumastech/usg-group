<?php

namespace App\Domain\Loans;

use App\Exceptions\InvalidLoanAmountException;
use App\Support\Kwacha;
use Brick\Money\Money;

/**
 * How many months a principal is given to repay.
 *
 * The constitution fixes the term by size of loan rather than by negotiation, so this
 * is a lookup, not a calculation:
 *
 *   K1,000–2,000 → 1 month      K10,001–18,000 → 6 months
 *   K2,001–5,000 → 2 months     K18,001–29,999 → 8 months
 *   K5,001–10,000 → 4 months    K30,000 and above → 10 months
 */
final class LoanTenor
{
    /** The smallest loan the group issues. */
    public const MINIMUM_PRINCIPAL_NGWEE = 100_000;

    /**
     * Upper bound of each band in ngwee, paired with the months it earns.
     *
     * @var array<int, array{0: int, 1: int}>
     */
    private const BANDS = [
        [200_000, 1],
        [500_000, 2],
        [1_000_000, 4],
        [1_800_000, 6],
        [2_999_999, 8],
    ];

    /** Anything at or above the top band's ceiling gets the longest term. */
    public const MAXIMUM_MONTHS = 10;

    private function __construct(
        public readonly int $months,
        public readonly int $principalNgwee,
    ) {}

    public static function for(Money $principal): self
    {
        return self::forNgwee(Kwacha::toNgwee($principal));
    }

    public static function forNgwee(int $ngwee): self
    {
        if ($ngwee < self::MINIMUM_PRINCIPAL_NGWEE) {
            throw new InvalidLoanAmountException(
                'The smallest loan the group issues is '.Kwacha::format(self::MINIMUM_PRINCIPAL_NGWEE).'.'
            );
        }

        foreach (self::BANDS as [$ceiling, $months]) {
            /*
             * The bands are written as whole Kwacha ranges. The lower ceilings are
             * inclusive — K2,000 earns one month — but the top band stops just under
             * K30,000, so only a principal of K30,000 or more reaches the ten-month term.
             */
            if ($ngwee <= $ceiling) {
                return new self($months, $ngwee);
            }
        }

        return new self(self::MAXIMUM_MONTHS, $ngwee);
    }

    /** The same term, shortened to fit what is left of the cycle. */
    public function compressedTo(int $months): self
    {
        return new self(max(1, min($this->months, $months)), $this->principalNgwee);
    }

    public function isCompressedFrom(self $original): bool
    {
        return $this->months < $original->months;
    }

    /**
     * Equal principal installments, in whole ngwee.
     *
     * Any remainder from the division lands on the final installment, so the parts
     * always add back to the principal exactly.
     *
     * @return array<int, int>
     */
    public function principalInstallmentsNgwee(): array
    {
        $base = intdiv($this->principalNgwee, $this->months);
        $installments = array_fill(0, $this->months, $base);
        $installments[$this->months - 1] += $this->principalNgwee - ($base * $this->months);

        return $installments;
    }
}
