<?php

namespace App\Http\Resources;

use App\Models\DiasporaApportionment;
use App\Models\DiasporaApportionmentItem;
use App\Support\Kwacha;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One declared split, with its checklist of transfers.
 *
 * @mixin DiasporaApportionment
 */
class DiasporaApportionmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'total_ngwee' => Kwacha::toNgwee($this->total_ngwee),
            'apportioned_ngwee' => Kwacha::toNgwee($this->apportioned_ngwee),
            'share_ngwee' => Kwacha::toNgwee($this->share_ngwee),
            'remainder_ngwee' => Kwacha::toNgwee($this->remainder_ngwee),
            'declared_on' => $this->declared_on->toDateString(),
            'recorded_by' => $this->whenLoaded('recordedBy', fn (): ?string => $this->recordedBy?->full_name),
            'second_approver' => $this->whenLoaded('secondApprover', fn (): ?string => $this->secondApprover?->full_name),
            'note' => $this->note,
            'items' => $this->whenLoaded('items', fn (): array => $this->items
                ->map(fn (DiasporaApportionmentItem $item): array => [
                    'id' => $item->id,
                    'member' => $item->member?->full_name,
                    'member_id' => $item->member_id,
                    'amount_ngwee' => Kwacha::toNgwee($item->amount_ngwee),
                    'status' => $item->status,
                    'status_label' => $item->status->label(),
                    'paid_on' => $item->paid_on?->toDateString(),
                    'reference' => $item->reference,
                    'abilities' => $user === null ? [] : [
                        'confirmTransfer' => $user->can('confirmTransfer', $item),
                    ],
                ])->values()->all()),
        ];
    }
}
