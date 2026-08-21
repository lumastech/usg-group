<?php

namespace App\Domain\Import;

/**
 * One historical figure the workbook holds and the ledgers may not.
 *
 * A plan is a list of these. Each carries its own natural key — member, month and kind
 * — which is what makes the import idempotent: running it twice finds the second copy
 * of every entry already posted and skips it rather than doubling the ledger.
 */
final readonly class PlannedEntry
{
    public function __construct(
        public string $kind,
        public int $memberId,
        public string $memberName,
        public int $memberNumber,
        public ?int $cycleMonthId,
        public string $monthLabel,
        public int $amountNgwee,
        public bool $alreadyPosted,
        public ?string $note = null,
        /**
         * Figures that travel with the entry but are not its amount, e.g. the
         * repayment and loan-request columns of a declaration row.
         *
         * @var array<string, int>
         */
        public array $extra = [],
    ) {}

    /** The natural key: what makes two readings of the workbook the same entry. */
    public function key(): string
    {
        return "{$this->kind}:{$this->memberId}:".($this->cycleMonthId ?? 0);
    }

    /**
     * @return array{
     *     kind: string,
     *     member_id: int,
     *     member_name: string,
     *     member_number: int,
     *     cycle_month_id: int|null,
     *     month_label: string,
     *     amount_ngwee: int,
     *     already_posted: bool,
     *     note: string|null,
     *     key: string,
     *     extra: array<string, int>,
     * }
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'member_id' => $this->memberId,
            'member_name' => $this->memberName,
            'member_number' => $this->memberNumber,
            'cycle_month_id' => $this->cycleMonthId,
            'month_label' => $this->monthLabel,
            'amount_ngwee' => $this->amountNgwee,
            'already_posted' => $this->alreadyPosted,
            'note' => $this->note,
            'key' => $this->key(),
            'extra' => $this->extra,
        ];
    }
}
