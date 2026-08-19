<?php

namespace Database\Factories;

use App\Enums\SavingsTransactionType;
use App\Enums\TransactionSource;
use App\Models\CycleMonth;
use App\Models\Member;
use App\Models\SavingsTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SavingsTransaction> */
class SavingsTransactionFactory extends Factory
{
    protected $model = SavingsTransaction::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'member_id' => Member::factory(),
            'cycle_month_id' => CycleMonth::factory(),
            'type' => SavingsTransactionType::Contribution,
            'amount_ngwee' => 50_000 * fake()->numberBetween(1, 10),
            'declared_amount_ngwee' => null,
            'recorded_by_member_id' => null,
            'source' => TransactionSource::Manual,
            'occurred_on' => now(),
        ];
    }

    public function ofKwacha(int $kwacha): static
    {
        return $this->state(['amount_ngwee' => $kwacha * 100]);
    }
}
