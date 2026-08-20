<?php

namespace Database\Factories;

use App\Enums\PayoutCase;
use App\Enums\SettlementStatus;
use App\Models\Cycle;
use App\Models\Member;
use App\Models\MemberDebt;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MemberDebt> */
class MemberDebtFactory extends Factory
{
    protected $model = MemberDebt::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'cycle_id' => Cycle::factory(),
            'member_id' => Member::factory(),
            'case' => PayoutCase::LeftEarly,
            'amount_owed_ngwee' => fake()->numberBetween(50_000, 2_000_000),
            'status' => SettlementStatus::Outstanding,
            'breakdown' => ['lines' => []],
        ];
    }
}
