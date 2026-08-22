<?php

namespace Database\Factories;

use App\Enums\MobileMoneyOperator;
use App\Enums\PayoutDestinationType;
use App\Models\Member;
use App\Models\PayoutDestination;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PayoutDestination> */
class PayoutDestinationFactory extends Factory
{
    protected $model = PayoutDestination::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'member_id' => Member::factory(),
            'type' => PayoutDestinationType::MobileMoney,
            'phone' => '097'.fake()->numerify('#######'),
            'operator' => MobileMoneyOperator::Airtel,
            'resolved_account_name' => fake()->name(),
            'name_match_score' => 100,
            'verified_at' => now(),
            'is_default' => true,
        ];
    }

    public function bankAccount(): static
    {
        return $this->state([
            'type' => PayoutDestinationType::BankAccount,
            'bank_id' => '002',
            'bank_name' => 'Absa Bank Zambia',
            'account_number' => fake()->numerify('##########'),
            'phone' => null,
            'operator' => null,
        ]);
    }

    public function mobileMoney(MobileMoneyOperator $operator = MobileMoneyOperator::Airtel): static
    {
        return $this->state([
            'type' => PayoutDestinationType::MobileMoney,
            'operator' => $operator,
            'bank_id' => null,
            'bank_name' => null,
            'account_number' => null,
        ]);
    }

    /** Captured but never checked against the provider — cannot be paid to. */
    public function unverified(): static
    {
        return $this->state(['verified_at' => null, 'resolved_account_name' => null, 'name_match_score' => null]);
    }

    public function nameMismatch(): static
    {
        return $this->state([
            'resolved_account_name' => 'Somebody Else Entirely',
            'name_match_score' => 10,
            'name_match_confirmed_at' => null,
        ]);
    }

    public function notDefault(): static
    {
        return $this->state(['is_default' => false]);
    }
}
