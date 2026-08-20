<?php

namespace Database\Factories;

use App\Enums\MotionType;
use App\Models\Cycle;
use App\Models\Member;
use App\Models\Motion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Motion> */
class MotionFactory extends Factory
{
    protected $model = Motion::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'cycle_id' => Cycle::factory(),
            'type' => MotionType::General,
            'subject' => $this->faker->sentence(),
            'proposed_by_member_id' => Member::factory(),
            'threshold_basis' => MotionType::General->thresholdBasis(),
        ];
    }

    public function type(MotionType $type): static
    {
        return $this->state([
            'type' => $type,
            'threshold_basis' => $type->thresholdBasis(),
        ]);
    }

    /** A motion already carried, for spacing and history assertions. */
    public function passed(?string $decidedAt = null): static
    {
        return $this->state([
            'votes_for' => 18,
            'votes_against' => 2,
            'eligible_count' => 30,
            'votes_needed' => 18,
            'passed' => true,
            'decided_at' => $decidedAt ?? now(),
        ]);
    }
}
