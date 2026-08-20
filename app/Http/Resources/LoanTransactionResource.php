<?php

namespace App\Http\Resources;

use App\Models\LoanTransaction;
use App\Support\Kwacha;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One line of a loan's ledger.
 *
 * Entries are never editable, so this carries no abilities map — a charge posted in
 * error is corrected by a reversing entry, which appears as its own line.
 *
 * @mixin LoanTransaction
 */
class LoanTransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'type_label' => $this->type->label(),
            'amount_ngwee' => Kwacha::toNgwee($this->amount_ngwee),
            'signed_amount_ngwee' => $this->signedAmountNgwee(),
            'balance_after_ngwee' => Kwacha::toNgwee($this->balance_after_ngwee),
            'principal_portion_ngwee' => Kwacha::toNgwee($this->principal_portion_ngwee),
            'interest_portion_ngwee' => Kwacha::toNgwee($this->interest_portion_ngwee),
            'penalty_portion_ngwee' => Kwacha::toNgwee($this->penalty_portion_ngwee),
            'occurred_on' => $this->occurred_on->toDateString(),
            'notes' => $this->notes,
            'month_label' => $this->whenLoaded('cycleMonth', fn (): ?string => $this->cycleMonth?->label()),
            'recorded_by' => $this->whenLoaded('recordedBy', fn (): ?string => $this->recordedBy?->full_name),
            'recorded_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
