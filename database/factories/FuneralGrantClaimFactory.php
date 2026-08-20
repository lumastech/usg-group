<?php

namespace Database\Factories;

use App\Enums\FuneralRelationship;
use App\Enums\GrantClaimStatus;
use App\Models\Cycle;
use App\Models\FuneralGrantClaim;
use App\Models\Member;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FuneralGrantClaim> */
class FuneralGrantClaimFactory extends Factory
{
    protected $model = FuneralGrantClaim::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'cycle_id' => Cycle::factory(),
            'member_id' => Member::factory(),
            'deceased_name' => fake()->name(),
            'relationship' => fake()->randomElement(FuneralRelationship::cases()),
            'claim_date' => now(),
            'status' => GrantClaimStatus::Submitted,
            'amount_ngwee' => 100_000,
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
