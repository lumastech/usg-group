<?php

namespace App\Http\Resources;

use App\Domain\Wallets\WalletLedger;
use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One wallet as a screen reads it.
 *
 * The balance is computed, never stored — see Wallet. Sending a cached figure to the
 * browser would be the one place a stale total could be believed.
 *
 * @mixin Wallet
 */
class WalletResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $balance = app(WalletLedger::class)->balanceNgwee($this->resource);

        return [
            'id' => $this->id,
            'member_id' => $this->member_id,
            'member_name' => $this->whenLoaded('member', fn (): ?string => $this->member?->full_name),
            'member_number' => $this->whenLoaded('member', fn (): ?int => $this->member?->member_number),
            'kind' => $this->kind,
            'status' => $this->status,
            'status_label' => $this->status->label(),
            'balance_ngwee' => $balance,
            'opened_at' => $this->opened_at->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
        ];
    }
}
