<?php

namespace Database\Factories;

use App\Enums\CollateralClaimStatus;
use App\Models\CollateralClaim;
use App\Models\Loan;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CollateralClaim> */
class CollateralClaimFactory extends Factory
{
    protected $model = CollateralClaim::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'loan_id' => Loan::factory(),
            'status' => CollateralClaimStatus::Draft,
            'items' => [
                ['description' => 'Deep freezer', 'estimated_value_ngwee' => 350_000],
                ['description' => 'Two-plate cooker', 'estimated_value_ngwee' => 120_000],
            ],
            'claimed_value_ngwee' => 470_000,
            'outstanding_at_claim_ngwee' => 450_000,
        ];
    }
}
