<?php

namespace Database\Factories;

use App\Enums\TransactionSource;
use App\Enums\WalletEntryType;
use App\Models\Wallet;
use App\Models\WalletEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WalletEntry> */
class WalletEntryFactory extends Factory
{
    protected $model = WalletEntry::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'wallet_id' => Wallet::factory(),
            'cycle_id' => fn (array $attributes): int => Wallet::withoutGlobalScopes()
                ->whereKey($attributes['wallet_id'])
                ->firstOrFail()
                ->cycle_id,
            'amount_ngwee' => 50_000,
            'type' => WalletEntryType::TopUp,
            'source' => TransactionSource::Gateway,
            'occurred_on' => now()->toDateString(),
        ];
    }

    /** A credit of a whole number of Kwacha. */
    public function ofKwacha(int $kwacha): static
    {
        return $this->state(['amount_ngwee' => abs($kwacha) * 100]);
    }

    /** A debit, which is how every outflow is stored. */
    public function debitOfKwacha(int $kwacha): static
    {
        return $this->state([
            'amount_ngwee' => -abs($kwacha) * 100,
            'type' => WalletEntryType::Withdrawal,
        ]);
    }
}
