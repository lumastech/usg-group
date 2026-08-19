<?php

namespace Database\Factories;

use App\Enums\CycleStatus;
use App\Enums\WeekendTradingPolicy;
use App\Models\Cycle;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/** @extends Factory<Cycle> */
class CycleFactory extends Factory
{
    protected $model = Cycle::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => '2025–2026',
            'starts_on' => Carbon::parse('2025-12-01'),
            'ends_on' => Carbon::parse('2026-11-30'),
            'registration_closes_after_month' => 3,
            'loan_lockdown_starts_month' => 10,
            'final_repayment_date' => Carbon::parse('2026-11-07'),
            'weekend_trading_policy' => WeekendTradingPolicy::NextMonday,
            'status' => CycleStatus::Active,
        ];
    }

    public function closing(): static
    {
        return $this->state(['status' => CycleStatus::Closing]);
    }

    public function previousFridayPolicy(): static
    {
        return $this->state(['weekend_trading_policy' => WeekendTradingPolicy::PreviousFriday]);
    }
}
