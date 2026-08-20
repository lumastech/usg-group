<?php

namespace App\Http\Resources;

use App\Models\FuneralGrantClaim;
use App\Models\UnityBabyClaim;
use App\Support\Kwacha;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A funeral or unity baby claim, shaped the same way for both tables.
 *
 * The `detail` field is what distinguishes them on screen — the deceased and the
 * relationship for one, the child and the birth date for the other — so the claims
 * DataTable renders either kind from one column set.
 *
 * @mixin FuneralGrantClaim|UnityBabyClaim
 */
class GrantClaimResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $isFuneral = $this->resource instanceof FuneralGrantClaim;

        return [
            'id' => $this->id,
            'grant' => $isFuneral ? 'funeral' : 'unity_baby',
            'member' => $this->whenLoaded('member', fn (): string => $this->member->full_name),
            'member_id' => $this->member_id,
            'detail' => $this->subject(),
            'relationship' => $isFuneral ? $this->relationship : null,
            'relationship_label' => $isFuneral ? $this->relationship->label() : null,
            'born_on' => $isFuneral ? null : $this->born_on->toDateString(),
            'claim_date' => $this->claim_date->toDateString(),
            'status' => $this->status,
            'status_label' => $this->status->label(),
            'amount_ngwee' => Kwacha::toNgwee($this->amount_ngwee),
            'first_approver' => $this->whenLoaded('firstApprover', fn (): ?string => $this->firstApprover?->full_name),
            'second_approver' => $this->whenLoaded('secondApprover', fn (): ?string => $this->secondApprover?->full_name),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'note' => $this->note,
            'abilities' => $user === null ? [] : [
                'approve' => $user->can('approve', $this->resource),
                'pay' => $user->can('pay', $this->resource),
                'reject' => $user->can('reject', $this->resource),
            ],
        ];
    }
}
