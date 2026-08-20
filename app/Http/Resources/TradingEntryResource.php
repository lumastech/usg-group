<?php

namespace App\Http\Resources;

use App\Models\TradingEntry;
use App\Support\Kwacha;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the trading-day sheet.
 *
 * The console works top-down through these, so each row carries everything the
 * treasurer needs to decide what to do next: what was promised, what has arrived, how
 * late it was and whether the member is owed a disbursement.
 *
 * @mixin TradingEntry
 */
class TradingEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'member_id' => $this->member_id,
            'member_name' => $this->whenLoaded('member', fn (): string => $this->member->full_name),
            'member_number' => $this->whenLoaded('member', fn (): int => $this->member->member_number),
            'declaration_id' => $this->declaration_id,
            'declared' => $this->whenLoaded('declaration', fn (): ?array => $this->declaration === null ? null : [
                'saving_amount_ngwee' => Kwacha::toNgwee($this->declaration->saving_amount_ngwee),
                'loan_repayment_amount_ngwee' => Kwacha::toNgwee($this->declaration->loan_repayment_amount_ngwee),
                'loan_requested_amount_ngwee' => Kwacha::toNgwee($this->declaration->loan_requested_amount_ngwee),
                'is_late' => $this->declaration->is_late,
            ]),
            'expected_in_ngwee' => Kwacha::toNgwee($this->expected_in_ngwee),
            'actual_in_ngwee' => Kwacha::toNgwee($this->actual_in_ngwee),
            'received_at' => $this->received_at?->toIso8601String(),
            'expected_out_ngwee' => Kwacha::toNgwee($this->expected_out_ngwee),
            'actual_out_ngwee' => Kwacha::toNgwee($this->actual_out_ngwee),
            'disbursed_at' => $this->disbursed_at?->toIso8601String(),
            'variance_ngwee' => Kwacha::toNgwee($this->variance_ngwee),
            'penalty_days' => $this->penalty_days,
            'savings_portion_ngwee' => Kwacha::toNgwee($this->savings_portion_ngwee),
            'repayment_portion_ngwee' => Kwacha::toNgwee($this->repayment_portion_ngwee),
            'is_received' => $this->isReceived(),
            'is_disbursed' => $this->isDisbursed(),
        ];
    }
}
