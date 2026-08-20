<?php

namespace Database\Factories;

use App\Enums\SettlementStatus;
use App\Models\Cycle;
use App\Models\Member;
use App\Models\NextOfKinRepaymentArrangement;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<NextOfKinRepaymentArrangement> */
class NextOfKinRepaymentArrangementFactory extends Factory
{
    protected $model = NextOfKinRepaymentArrangement::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'cycle_id' => Cycle::factory(),
            'member_id' => Member::factory(),
            'amount_owed_ngwee' => fake()->numberBetween(50_000, 2_000_000),
            'agreed_terms' => 'Repaid in six monthly instalments from the estate.',
            'status' => SettlementStatus::Outstanding,
            'breakdown' => ['lines' => []],
            'agreed_on' => now(),
        ];
    }
}
