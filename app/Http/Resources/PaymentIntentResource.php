<?php

namespace App\Http\Resources;

use App\Models\PaymentIntent;
use App\Support\Kwacha;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One payment as the portal renders it.
 *
 * Two labels rather than one: `status_label` is what the committee reads on the
 * payments screen, `member_status_label` is what the member reads on theirs. "Approve
 * the prompt on your phone" is an instruction; "Awaiting authorisation" is a state.
 *
 * @mixin PaymentIntent
 */
class PaymentIntentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'provider_reference' => $this->provider_reference,
            'direction' => $this->direction,
            'direction_label' => $this->direction->label(),
            'purpose' => $this->purpose,
            'purpose_label' => $this->purpose->label(),
            'channel' => $this->channel,
            'channel_label' => $this->channel->label(),
            'status' => $this->status,
            'status_label' => $this->status->label(),
            'member_status_label' => $this->status->memberLabel(),
            'status_reason' => $this->status_reason,

            'amount_ngwee' => Kwacha::toNgwee($this->amount_ngwee),
            'fee_ngwee' => $this->fee_ngwee === null ? null : Kwacha::toNgwee($this->fee_ngwee),
            'fee_bearer' => $this->fee_bearer,
            'member_cost_ngwee' => $this->memberCostNgwee(),

            'member_id' => $this->member_id,
            'member_name' => $this->whenLoaded('member', fn (): ?string => $this->member?->full_name),
            'member_number' => $this->whenLoaded('member', fn (): ?int => $this->member?->member_number),
            'destination' => $this->whenLoaded('destination', fn (): ?string => $this->destination?->label()),
            'requested_by' => $this->whenLoaded('requestedBy', fn (): ?string => $this->requestedBy?->full_name),

            'payable_type' => $this->payable_type === null ? null : class_basename($this->payable_type),
            'payable_id' => $this->payable_id,

            'attempt' => $this->attempt,
            'is_posted' => $this->isPosted(),
            /* An unanswered prompt the member may now give up on and try again. */
            'has_stalled' => $this->hasStalled(),
            'initiated_at' => $this->initiated_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'settled_at' => $this->settled_at?->toIso8601String(),
            'posted_at' => $this->posted_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),

            'abilities' => $this->abilities($request),
        ];
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
            'retry' => $user->can('retry', $this->resource),
            'refresh' => $user->can('refresh', $this->resource),
            'resolve' => $user->can('resolve', $this->resource),
        ];
    }
}
