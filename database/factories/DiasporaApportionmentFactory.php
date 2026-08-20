<?php

namespace Database\Factories;

use App\Models\Cycle;
use App\Models\DiasporaApportionment;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DiasporaApportionment> */
class DiasporaApportionmentFactory extends Factory
{
    protected $model = DiasporaApportionment::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'cycle_id' => Cycle::factory(),
            'total_ngwee' => 100_000,
            'apportioned_ngwee' => 100_000,
            'share_ngwee' => 50_000,
            'remainder_ngwee' => 0,
            'declared_on' => now(),
        ];
    }
}
