<?php

namespace Database\Factories;

use App\Enums\NextOfKinRelationship;
use App\Models\Member;
use App\Models\NextOfKin;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<NextOfKin> */
class NextOfKinFactory extends Factory
{
    protected $model = NextOfKin::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $label = fake()->randomElement(['Spouse', 'Sister', 'Mother', 'Daughter', 'Brother']);

        return [
            'member_id' => Member::factory(),
            'name' => fake()->name(),
            'phone' => fake()->numerify('09########'),
            'relationship' => NextOfKinRelationship::fromLabel($label),
            'relationship_label' => $label,
        ];
    }
}
