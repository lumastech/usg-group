<?php

namespace App\Http\Resources;

use App\Models\SavingsTransaction;
use App\Support\Kwacha;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One line of a member's savings ledger.
 *
 * Entries are never editable, so this carries no abilities map — the statement is a
 * record of what happened, and a correction appears as its own reversing line.
 *
 * @mixin SavingsTransaction
 */
class SavingsTransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'source' => $this->source,
            'amount_ngwee' => Kwacha::toNgwee($this->amount_ngwee),
            'declared_amount_ngwee' => $this->declared_amount_ngwee === null
                ? null
                : Kwacha::toNgwee($this->declared_amount_ngwee),
            'variance_ngwee' => $this->varianceNgwee(),
            'occurred_on' => $this->occurred_on->toDateString(),
            'note' => $this->note,
            'month_label' => $this->whenLoaded('cycleMonth', fn (): string => $this->cycleMonth->label()),
            'month_sequence' => $this->whenLoaded('cycleMonth', fn (): int => $this->cycleMonth->sequence),
            'recorded_by' => $this->whenLoaded('recordedBy', fn (): ?string => $this->recordedBy?->full_name),
            'recorded_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
