<?php

namespace Database\Factories;

use App\Enums\TradingSessionStatus;
use App\Models\Cycle;
use App\Models\CycleMonth;
use App\Models\TradingSession;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/** @extends Factory<TradingSession> */
class TradingSessionFactory extends Factory
{
    protected $model = TradingSession::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'cycle_id' => Cycle::factory(),
            'cycle_month_id' => CycleMonth::factory(),
            'scheduled_conclude_date' => Carbon::parse('2026-01-07'),
            'status' => TradingSessionStatus::Open,
        ];
    }

    public function concluded(): static
    {
        return $this->state([
            'status' => TradingSessionStatus::Concluded,
            'concluded_at' => now(),
        ]);
    }
}
