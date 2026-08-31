<?php

namespace Database\Factories;

use App\Enums\WalletTransferPurpose;
use App\Models\Wallet;
use App\Models\WalletTransfer;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WalletTransfer> */
class WalletTransferFactory extends Factory
{
    protected $model = WalletTransfer::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'from_wallet_id' => Wallet::factory(),
            'to_wallet_id' => Wallet::factory()->group(),
            'cycle_id' => fn (array $attributes): int => Wallet::withoutGlobalScopes()
                ->whereKey($attributes['from_wallet_id'])
                ->firstOrFail()
                ->cycle_id,
            'amount_ngwee' => 50_000,
            'purpose' => WalletTransferPurpose::SavingsContribution,
            'occurred_at' => now(),
        ];
    }
}
