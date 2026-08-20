<?php

namespace Database\Factories;

use App\Enums\LoanScheduleItemStatus;
use App\Models\CycleMonth;
use App\Models\Loan;
use App\Models\LoanScheduleItem;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/** @extends Factory<LoanScheduleItem> */
class LoanScheduleItemFactory extends Factory
{
    protected $model = LoanScheduleItem::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $month = Carbon::parse('2026-02-01');

        return [
            'loan_id' => Loan::factory(),
            'cycle_month_id' => CycleMonth::factory(),
            'sequence' => 1,
            'due_month' => $month,
            'due_on' => $month->copy()->addDays(6),
            'original_principal_ngwee' => 250_000,
            'original_interest_ngwee' => 50_000,
            'original_amount_due_ngwee' => 300_000,
            'principal_due_ngwee' => 250_000,
            'interest_due_ngwee' => 50_000,
            'amount_due_ngwee' => 300_000,
            'amount_paid_ngwee' => 0,
            'status' => LoanScheduleItemStatus::Pending,
        ];
    }
}
