<?php

namespace Database\Factories;

use App\Models\Cycle;
use App\Models\PaymentReconciliation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PaymentReconciliation> */
class PaymentReconciliationFactory extends Factory
{
    protected $model = PaymentReconciliation::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'cycle_id' => Cycle::factory(),
            'for_date' => today(),
            'collections_count' => 0,
            'collections_ngwee' => 0,
            'transfers_count' => 0,
            'transfers_ngwee' => 0,
            'fees_ngwee' => 0,
            'unmatched' => [],
            'unmatched_count' => 0,
            'ran_at' => now(),
        ];
    }
}
