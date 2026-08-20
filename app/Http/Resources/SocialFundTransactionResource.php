<?php

namespace App\Http\Resources;

use App\Models\SocialFundTransaction;
use App\Support\Kwacha;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One line of the Social Fund ledger.
 *
 * Entries are never editable, so this carries no abilities map — a correction appears
 * as its own reversing line rather than by rewriting history.
 *
 * @mixin SocialFundTransaction
 */
class SocialFundTransactionResource extends JsonResource
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
            'is_outflow' => Kwacha::toNgwee($this->amount_ngwee) < 0,
            'occurred_on' => $this->occurred_on->toDateString(),
            'member' => $this->whenLoaded('member', fn (): ?string => $this->member?->full_name),
            'member_id' => $this->member_id,
            'month_label' => $this->whenLoaded('cycleMonth', fn (): ?string => $this->cycleMonth?->label()),
            'recorded_by' => $this->whenLoaded('recordedBy', fn (): ?string => $this->recordedBy?->full_name),
            'second_approver' => $this->whenLoaded('secondApprover', fn (): ?string => $this->secondApprover?->full_name),
            'note' => $this->note,
            'recorded_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
