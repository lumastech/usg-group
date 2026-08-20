<?php

namespace Database\Factories;

use App\Enums\GrantClaimStatus;
use App\Models\Cycle;
use App\Models\Member;
use App\Models\UnityBabyClaim;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<UnityBabyClaim> */
class UnityBabyClaimFactory extends Factory
{
    protected $model = UnityBabyClaim::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'cycle_id' => Cycle::factory(),
            'member_id' => Member::factory(),
            'child_name' => fake()->firstName(),
            'born_on' => now()->subWeeks(2),
            'claim_date' => now(),
            'status' => GrantClaimStatus::Submitted,
            'amount_ngwee' => 50_000,
        ];
    }

    public function approvedBy(Member $first, Member $second): static
    {
        return $this->state([
            'status' => GrantClaimStatus::Approved,
            'first_approver_member_id' => $first->id,
            'second_approver_member_id' => $second->id,
            'approved_at' => now(),
        ]);
    }
}
