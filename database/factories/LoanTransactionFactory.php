<?php

namespace Database\Factories;

use App\Enums\LoanTransactionType;
use App\Models\Loan;
use App\Models\LoanTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/** @extends Factory<LoanTransaction> */
class LoanTransactionFactory extends Factory
{
    protected $model = LoanTransaction::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'loan_id' => Loan::factory(),
            'type' => LoanTransactionType::Disbursement,
            'amount_ngwee' => 1_000_000,
            'occurred_on' => Carbon::parse('2026-01-07'),
            'balance_after_ngwee' => 1_000_000,
        ];
    }
}
