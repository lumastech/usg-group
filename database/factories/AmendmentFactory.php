<?php

namespace Database\Factories;

use App\Enums\MotionType;
use App\Models\Amendment;
use App\Models\Cycle;
use App\Models\Motion;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/** @extends Factory<Amendment> */
class AmendmentFactory extends Factory
{
    protected $model = Amendment::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'cycle_id' => Cycle::factory(),
            'motion_id' => Motion::factory()->type(MotionType::Amendment),
            'section_reference' => 'Section '.$this->faker->numberBetween(1, 20),
            'current_text' => $this->faker->paragraph(),
            'proposed_text' => $this->faker->paragraph(),
            'effective_date' => Carbon::parse('2026-06-01'),
        ];
    }
}
