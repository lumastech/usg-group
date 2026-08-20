<?php

namespace App\Http\Resources;

use App\Models\Declaration;
use App\Support\Kwacha;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One member's promise for one month.
 *
 * `total_expected_payment_ngwee` is signed on purpose: a member taking a loan larger
 * than their savings and repayment leaves the table with money, and the screens render
 * that negative figure in red rather than hiding it.
 *
 * @mixin Declaration
 */
class DeclarationResource extends JsonResource
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
            'cycle_month_id' => $this->cycle_month_id,
            'month_label' => $this->whenLoaded('cycleMonth', fn (): string => $this->cycleMonth->label()),
            'month_sequence' => $this->whenLoaded('cycleMonth', fn (): int => $this->cycleMonth->sequence),
            'saving_amount_ngwee' => Kwacha::toNgwee($this->saving_amount_ngwee),
            'loan_repayment_amount_ngwee' => Kwacha::toNgwee($this->loan_repayment_amount_ngwee),
            'loan_requested_amount_ngwee' => Kwacha::toNgwee($this->loan_requested_amount_ngwee),
            'total_expected_payment_ngwee' => Kwacha::toNgwee($this->total_expected_payment_ngwee),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'is_late' => $this->is_late,
            'status' => $this->status,
            'status_label' => $this->status->label(),
            'note' => $this->note,
            'recorded_by' => $this->whenLoaded('recordedBy', fn (): ?string => $this->recordedBy?->full_name),
            'abilities' => [
                'update' => $request->user()?->can('update', $this->resource) ?? false,
            ],
        ];
    }
}
