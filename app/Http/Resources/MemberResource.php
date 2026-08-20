<?php

namespace App\Http\Resources;

use App\Models\Member;
use App\Support\Kwacha;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One member as the portal renders them.
 *
 * Money is always sent as an integer of ngwee — formatting is the frontend's job —
 * and `abilities` carries the policy's real answers so a button is never shown for
 * something the server would refuse.
 *
 * @mixin Member
 */
class MemberResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'member_number' => $this->member_number,
            'full_name' => $this->full_name,
            'nrc_number' => $this->nrc_number,
            'phone' => $this->phone,
            'physical_address' => $this->physical_address,
            'is_diaspora' => $this->is_diaspora,
            'status' => $this->status,
            'status_label' => $this->status->label(),
            'status_reason' => $this->status_reason,
            'status_effective_on' => $this->status_effective_on?->toDateString(),
            'status_changed_at' => $this->status_changed_at?->toIso8601String(),
            'expulsion_ground' => $this->expulsion_ground,
            'expulsion_ground_label' => $this->expulsion_ground?->label(),
            'date_of_death' => $this->date_of_death?->toDateString(),
            'joined_on' => $this->joined_on->toDateString(),
            'joining_month_sequence' => $this->joining_month_sequence,
            'joining_fee_ngwee' => Kwacha::toNgwee($this->joining_fee_ngwee),
            'joining_fee_paid' => $this->joining_fee_paid,
            'has_login' => $this->hasLogin(),
            'email' => $this->whenLoaded('user', fn (): ?string => $this->user?->email),
            'next_of_kin' => NextOfKinResource::collection($this->whenLoaded('nextOfKin')),

            // Filled by modules 2 and 3; sent now so the columns exist from day one.
            'savings_ngwee' => $this->savingsTotalNgwee(),
            'loan_balance_ngwee' => null,

            'abilities' => $this->abilities($request),
        ];
    }

    /** The withSum aggregate, present only when the query asked for it. */
    protected function savingsTotalNgwee(): ?int
    {
        $total = $this->resource->getAttributes()['savings_transactions_sum_amount_ngwee'] ?? null;

        return $total === null ? null : (int) $total;
    }

    /**
     * @return array<string, bool>
     */
    protected function abilities(Request $request): array
    {
        $user = $request->user();

        if ($user === null) {
            return [];
        }

        return [
            'view' => $user->can('view', $this->resource),
            'update' => $user->can('update', $this->resource),
            'changeStatus' => $user->can('changeStatus', $this->resource),
            'invite' => $user->can('invite', $this->resource),
        ];
    }
}
