<?php

namespace Database\Factories;

use App\Enums\DeclarationStatus;
use App\Models\Cycle;
use App\Models\CycleMonth;
use App\Models\Declaration;
use App\Models\Member;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Declaration> */
class DeclarationFactory extends Factory
{
    protected $model = Declaration::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'cycle_id' => Cycle::factory(),
            'cycle_month_id' => CycleMonth::factory(),
            'member_id' => Member::factory(),
            'saving_amount_ngwee' => 50_000,
            'loan_repayment_amount_ngwee' => 0,
            'loan_requested_amount_ngwee' => 0,
            'total_expected_payment_ngwee' => 50_000,
            'submitted_at' => now(),
            'is_late' => false,
            'status' => DeclarationStatus::Submitted,
        ];
    }

    /** Savings, repayment and request in Kwacha; the total follows from them. */
    public function amounts(int $saving, int $repayment = 0, int $requested = 0): static
    {
        return $this->state([
            'saving_amount_ngwee' => $saving * 100,
            'loan_repayment_amount_ngwee' => $repayment * 100,
            'loan_requested_amount_ngwee' => $requested * 100,
            'total_expected_payment_ngwee' => ($saving + $repayment - $requested) * 100,
        ]);
    }

    /** The committee has asked for it: no longer editable, and payable. */
    public function approved(): static
    {
        return $this->state([
            'status' => DeclarationStatus::Approved,
            'approved_at' => now(),
        ]);
    }

    public function locked(): static
    {
        return $this->state(['status' => DeclarationStatus::Locked]);
    }

    public function late(): static
    {
        return $this->state(['is_late' => true]);
    }
}
