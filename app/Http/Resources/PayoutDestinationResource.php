<?php

namespace App\Http\Resources;

use App\Domain\Payments\AccountNameMatcher;
use App\Models\PayoutDestination;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Where a member is paid, as the portal shows it.
 *
 * The account number is masked and the resolved name is not: the name is the thing
 * somebody is meant to look at and recognise, and the number is the thing nobody needs
 * to read over a shoulder.
 *
 * @mixin PayoutDestination
 */
class PayoutDestinationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'member_id' => $this->member_id,
            'type' => $this->type,
            'type_label' => $this->type->label(),
            'label' => $this->label(),
            'masked_identifier' => $this->maskedIdentifier(),
            'bank_name' => $this->bank_name,
            'operator' => $this->operator,
            'operator_label' => $this->operator?->label(),

            'resolved_account_name' => $this->resolved_account_name,
            'name_match_score' => $this->name_match_score,
            'name_matches' => $this->name_match_score !== null
                && AccountNameMatcher::isConfident($this->name_match_score),
            'name_match_confirmed_at' => $this->name_match_confirmed_at?->toIso8601String(),
            'needs_name_confirmation' => $this->hasUnconfirmedNameMismatch(),

            'is_default' => $this->is_default,
            'is_usable' => $this->isUsable(),
            'is_new' => $this->isWithinCoolingOff(),
            'verified_at' => $this->verified_at?->toIso8601String(),
            'disabled_at' => $this->disabled_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

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
            'update' => $user->can('update', $this->resource),
            'delete' => $user->can('delete', $this->resource),
            'confirmName' => $user->can('confirmName', $this->resource),
        ];
    }
}
