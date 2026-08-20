<?php

namespace App\Http\Resources;

use App\Models\CollateralClaim;
use App\Support\Kwacha;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The claim raised against a defaulting member's pledged goods.
 *
 * @mixin CollateralClaim
 */
class CollateralClaimResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'loan_id' => $this->loan_id,
            'status' => $this->status,
            'status_label' => $this->status->label(),
            'items' => $this->items,
            'claimed_value_ngwee' => Kwacha::toNgwee($this->claimed_value_ngwee),
            'outstanding_at_claim_ngwee' => Kwacha::toNgwee($this->outstanding_at_claim_ngwee),
            'covers_outstanding' => $this->coversOutstanding(),
            'prepared_by' => $this->whenLoaded('preparedBy', fn (): ?string => $this->preparedBy?->full_name),
            'second_signer' => $this->whenLoaded('secondSigner', fn (): ?string => $this->secondSigner?->full_name),
            'signed_off_at' => $this->signed_off_at?->toIso8601String(),
            'enforced_at' => $this->enforced_at?->toIso8601String(),
            'released_at' => $this->released_at?->toIso8601String(),
            'note' => $this->note,
            'abilities' => $user === null ? [] : [
                'signOff' => $user->can('signOff', $this->resource),
                'enforce' => $user->can('enforce', $this->resource),
                'release' => $user->can('release', $this->resource),
            ],
        ];
    }
}
