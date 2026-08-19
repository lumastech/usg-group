<?php

namespace Database\Factories;

use App\Enums\CycleMonthStatus;
use App\Enums\InterestAllocationMethod;
use App\Models\Cycle;
use App\Models\CycleMonth;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/** @extends Factory<CycleMonth> */
class CycleMonthFactory extends Factory
{
    protected $model = CycleMonth::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $month = Carbon::parse('2026-01-01');

        return [
            'cycle_id' => Cycle::factory(),
            'sequence' => 2,
            'month' => $month,
            'declarations_open_at' => $month->copy()->setTime(8, 0),
            'declarations_close_at' => $month->copy()->addDays(2)->endOfDay(),
            'trading_starts_on' => $month->copy()->addDays(3),
            'trading_concludes_on' => $month->copy()->addDays(6),
            'disbursement_on' => $month->copy()->addDays(6),
            'interest_allocation_method' => InterestAllocationMethod::PooledProRata,
            'status' => CycleMonthStatus::Pending,
        ];
    }
}
