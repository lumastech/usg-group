<?php

namespace App\Domain\Payouts;

use App\Enums\PayoutCase;
use App\Models\Member;
use App\Support\Kwacha;
use Brick\Money\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * What one member is owed, and how that was arrived at.
 *
 * A pure value: computing a breakdown writes nothing. PayoutExecutor is what turns
 * one into a payout, a debt or a repayment arrangement, and it stores the lines as
 * they were so a voucher reprinted later still reads as it did on the day.
 */
final readonly class PayoutBreakdown
{
    /**
     * @param  array<int, PayoutLine>  $lines
     * @param  CarbonInterface|null  $interestCutoff  the day interest stopped, for a death
     */
    public function __construct(
        public PayoutCase $case,
        public int $memberId,
        public array $lines,
        public int $netValueNgwee,
        public int $roundOffNgwee,
        public int $netPayableNgwee,
        public CarbonInterface $computedAt,
        public ?CarbonInterface $interestCutoff = null,
    ) {}

    /**
     * @param  array<int, PayoutLine>  $lines
     */
    public static function make(
        PayoutCase $case,
        Member $member,
        array $lines,
        int $netValueNgwee,
        int $roundOffNgwee,
        ?CarbonInterface $interestCutoff = null,
    ): self {
        return new self(
            $case,
            $member->id,
            $lines,
            $netValueNgwee,
            $roundOffNgwee,
            $netValueNgwee + $roundOffNgwee,
            Carbon::now(),
            $interestCutoff,
        );
    }

    /** A member whose loans outran their savings is owed nothing and owes the difference. */
    public function isNegative(): bool
    {
        return $this->netPayableNgwee < 0;
    }

    /** What the member owes the group, as a positive figure. Zero when they are in credit. */
    public function shortfallNgwee(): int
    {
        return max(0, -$this->netPayableNgwee);
    }

    /** What is actually handed over. Never negative — a shortfall is recorded, not paid. */
    public function payableNgwee(): int
    {
        return max(0, $this->netPayableNgwee);
    }

    public function netPayable(): Money
    {
        return Kwacha::ofNgwee($this->netPayableNgwee);
    }

    /**
     * The lines that make up the arithmetic, for asserting the statement adds up.
     *
     * @return array<int, PayoutLine>
     */
    public function countingLines(): array
    {
        return array_values(array_filter($this->lines, fn (PayoutLine $line): bool => $line->counts()));
    }

    /**
     * @return array{
     *     case: string,
     *     member_id: int,
     *     lines: array<int, array{label: string, formula: string, amount_ngwee: int, kind: string}>,
     *     net_value_ngwee: int,
     *     round_off_ngwee: int,
     *     net_payable_ngwee: int,
     *     payable_ngwee: int,
     *     shortfall_ngwee: int,
     *     is_negative: bool,
     *     interest_cutoff: string|null,
     *     computed_at: string,
     * }
     */
    public function toArray(): array
    {
        return [
            'case' => $this->case->value,
            'member_id' => $this->memberId,
            'lines' => array_map(fn (PayoutLine $line): array => $line->toArray(), $this->lines),
            'net_value_ngwee' => $this->netValueNgwee,
            'round_off_ngwee' => $this->roundOffNgwee,
            'net_payable_ngwee' => $this->netPayableNgwee,
            'payable_ngwee' => $this->payableNgwee(),
            'shortfall_ngwee' => $this->shortfallNgwee(),
            'is_negative' => $this->isNegative(),
            'interest_cutoff' => $this->interestCutoff?->toDateString(),
            'computed_at' => $this->computedAt->toIso8601String(),
        ];
    }
}
