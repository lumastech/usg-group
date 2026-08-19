<?php

namespace Database\Factories;

use App\Enums\MemberStatus;
use App\Models\Cycle;
use App\Models\Member;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/** @extends Factory<Member> */
class MemberFactory extends Factory
{
    protected $model = Member::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'cycle_id' => Cycle::factory(),
            'user_id' => null,
            'member_number' => fake()->unique()->numberBetween(1, 5000),
            'full_name' => fake()->name('female'),
            'nrc_number' => fake()->unique()->numerify('######/##/#'),
            'physical_address' => fake()->address(),
            'phone' => fake()->numerify('09########'),
            'next_of_kin_name' => fake()->name(),
            'next_of_kin_phone' => fake()->numerify('09########'),
            'next_of_kin_relationship' => fake()->randomElement(['Spouse', 'Sister', 'Mother', 'Daughter']),
            'is_diaspora' => false,
            'status' => MemberStatus::Active,
            'joined_on' => Carbon::parse('2025-12-01'),
            'joining_month_sequence' => 1,
            'joining_fee_ngwee' => 100_000,
            'joining_fee_paid' => true,
        ];
    }

    public function diaspora(): static
    {
        return $this->state(['is_diaspora' => true]);
    }

    public function leftEarly(): static
    {
        return $this->state(['status' => MemberStatus::LeftEarly]);
    }

    public function expelled(): static
    {
        return $this->state(['status' => MemberStatus::Expelled]);
    }

    public function deceased(): static
    {
        return $this->state(['status' => MemberStatus::Deceased]);
    }
}
