<?php

namespace App\Http\Resources;

use App\Models\LoanScheduleItem;
use App\Support\Kwacha;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One month of a repayment schedule, carrying both the figures the member was handed
 * at disbursement and the ones that apply now.
 *
 * @mixin LoanScheduleItem
 */
class LoanScheduleItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sequence' => $this->sequence,
            'month_label' => $this->whenLoaded('cycleMonth', fn (): string => $this->cycleMonth->label()),
            'due_month' => $this->due_month->toDateString(),
            'due_on' => $this->due_on->toDateString(),
            'original_principal_ngwee' => Kwacha::toNgwee($this->original_principal_ngwee),
            'original_interest_ngwee' => Kwacha::toNgwee($this->original_interest_ngwee),
            'original_amount_due_ngwee' => Kwacha::toNgwee($this->original_amount_due_ngwee),
            'principal_due_ngwee' => Kwacha::toNgwee($this->principal_due_ngwee),
            'interest_due_ngwee' => Kwacha::toNgwee($this->interest_due_ngwee),
            'amount_due_ngwee' => Kwacha::toNgwee($this->amount_due_ngwee),
            'amount_paid_ngwee' => Kwacha::toNgwee($this->amount_paid_ngwee),
            'outstanding_ngwee' => $this->outstandingNgwee(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'status' => $this->status,
            'status_label' => $this->status->label(),
        ];
    }
}
