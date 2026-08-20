<?php

namespace App\Domain\Payouts;

use App\Enums\PayoutLineKind;

/**
 * One line of a payout statement.
 *
 * Every line carries the formula it came from, because a member being handed their
 * share-out asks "where does that figure come from?" and the answer should be on the
 * paper rather than in someone's head.
 */
final readonly class PayoutLine
{
    public function __construct(
        public string $label,
        public string $formula,
        public int $amountNgwee,
        public PayoutLineKind $kind = PayoutLineKind::Credit,
    ) {}

    public static function credit(string $label, string $formula, int $amountNgwee): self
    {
        return new self($label, $formula, $amountNgwee, PayoutLineKind::Credit);
    }

    /** A deduction. The amount is stored negative, so the lines simply sum. */
    public static function debit(string $label, string $formula, int $amountNgwee): self
    {
        return new self($label, $formula, -abs($amountNgwee), PayoutLineKind::Debit);
    }

    public static function subtotal(string $label, string $formula, int $amountNgwee): self
    {
        return new self($label, $formula, $amountNgwee, PayoutLineKind::Subtotal);
    }

    public static function adjustment(string $label, string $formula, int $amountNgwee): self
    {
        return new self($label, $formula, $amountNgwee, PayoutLineKind::Adjustment);
    }

    public static function total(string $label, string $formula, int $amountNgwee): self
    {
        return new self($label, $formula, $amountNgwee, PayoutLineKind::Total);
    }

    /** A line that explains rather than counts; its amount never enters the sum. */
    public static function note(string $label, string $formula, int $amountNgwee = 0): self
    {
        return new self($label, $formula, $amountNgwee, PayoutLineKind::Note);
    }

    /** Whether this line is part of the arithmetic rather than a rule or a remark. */
    public function counts(): bool
    {
        return $this->kind === PayoutLineKind::Credit
            || $this->kind === PayoutLineKind::Debit
            || $this->kind === PayoutLineKind::Adjustment;
    }

    /** @return array{label: string, formula: string, amount_ngwee: int, kind: string} */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'formula' => $this->formula,
            'amount_ngwee' => $this->amountNgwee,
            'kind' => $this->kind->value,
        ];
    }

    /** @param  array{label: string, formula: string, amount_ngwee: int, kind: string}  $line */
    public static function fromArray(array $line): self
    {
        return new self(
            $line['label'],
            $line['formula'],
            (int) $line['amount_ngwee'],
            PayoutLineKind::from($line['kind']),
        );
    }
}
