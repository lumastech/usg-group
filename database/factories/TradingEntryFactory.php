<?php

namespace Database\Factories;

use App\Models\Member;
use App\Models\TradingEntry;
use App\Models\TradingSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TradingEntry> */
class TradingEntryFactory extends Factory
{
    protected $model = TradingEntry::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'trading_session_id' => TradingSession::factory(),
            'member_id' => Member::factory(),
            'declaration_id' => null,
            'expected_in_ngwee' => 50_000,
            'actual_in_ngwee' => 0,
            'expected_out_ngwee' => 0,
            'actual_out_ngwee' => 0,
            'variance_ngwee' => 0,
            'penalty_days' => 0,
            'savings_portion_ngwee' => 50_000,
            'repayment_portion_ngwee' => 0,
        ];
    }
}
