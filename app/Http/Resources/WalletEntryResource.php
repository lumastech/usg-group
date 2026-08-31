<?php

namespace App\Http\Resources;

use App\Models\WalletEntry;
use App\Support\Kwacha;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One line of a wallet statement.
 *
 * The amount keeps its sign all the way to the screen: a member reading their own
 * statement should see money leaving as a negative, not as a positive with a word
 * beside it that they have to interpret.
 *
 * @mixin WalletEntry
 */
class WalletEntryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'amount_ngwee' => Kwacha::toNgwee($this->amount_ngwee),
            'type' => $this->type,
            'type_label' => $this->type->label(),
            'source' => $this->source,
            'is_credit' => $this->isCredit(),
            'note' => $this->note,
            'occurred_on' => $this->occurred_on->toDateString(),
            'counterparty' => $this->whenLoaded(
                'counterparty',
                fn (): ?string => $this->counterparty?->label(),
            ),
            'reverses_id' => $this->reverses_wallet_entry_id,
            'payment_reference' => $this->whenLoaded(
                'paymentIntent',
                fn (): ?string => $this->paymentIntent?->reference,
            ),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
