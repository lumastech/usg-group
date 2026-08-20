<?php

namespace Database\Factories;

use App\Enums\PayoutCase;
use App\Models\Cycle;
use App\Models\Member;
use App\Models\Payout;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Payout> */
class PayoutFactory extends Factory
{
    protected $model = Payout::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $net = fake()->numberBetween(100_000, 5_000_000);

        return [
            'cycle_id' => Cycle::factory(),
            'member_id' => Member::factory(),
            'case' => PayoutCase::ActiveShareOut,
            'breakdown' => ['case' => PayoutCase::ActiveShareOut->value, 'lines' => []],
            'net_value_ngwee' => $net,
            'round_off_ngwee' => 0,
            'amount_ngwee' => $net,
            'executed_at' => now(),
        ];
    }

    public function executedBy(Member $actor, Member $secondApprover): static
    {
        return $this->state([
            'executed_by_member_id' => $actor->id,
            'second_approver_member_id' => $secondApprover->id,
        ]);
    }
}
