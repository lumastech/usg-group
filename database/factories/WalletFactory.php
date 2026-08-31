<?php

namespace Database\Factories;

use App\Enums\WalletKind;
use App\Enums\WalletStatus;
use App\Models\Cycle;
use App\Models\Member;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Wallet> */
class WalletFactory extends Factory
{
    protected $model = Wallet::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'cycle_id' => Cycle::factory(),
            'member_id' => Member::factory(),
            'kind' => WalletKind::Member,
            'status' => WalletStatus::Open,
            'opened_at' => now(),
        ];
    }

    /** The group's own wallet: no member, and the other side of every transfer. */
    public function group(): static
    {
        return $this->state([
            'member_id' => null,
            'kind' => WalletKind::Group,
        ]);
    }

    public function frozen(): static
    {
        return $this->state(['status' => WalletStatus::Frozen]);
    }

    public function closed(): static
    {
        return $this->state(['status' => WalletStatus::Closed, 'closed_at' => now()]);
    }
}
