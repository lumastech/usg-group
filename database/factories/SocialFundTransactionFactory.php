<?php

namespace Database\Factories;

use App\Enums\SocialFundTransactionType;
use App\Models\Cycle;
use App\Models\Member;
use App\Models\SocialFundTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SocialFundTransaction> */
class SocialFundTransactionFactory extends Factory
{
    protected $model = SocialFundTransaction::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'cycle_id' => Cycle::factory(),
            'cycle_month_id' => null,
            'member_id' => Member::factory(),
            'type' => SocialFundTransactionType::Contribution,
            'amount_ngwee' => 25_000,
            'occurred_on' => now(),
        ];
    }

    /** An entry that puts money in, whatever its type. */
    public function inflowOfKwacha(int $kwacha): static
    {
        return $this->state(['amount_ngwee' => abs($kwacha) * 100]);
    }
}
