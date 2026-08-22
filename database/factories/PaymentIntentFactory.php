<?php

namespace Database\Factories;

use App\Enums\FeeBearer;
use App\Enums\PaymentChannel;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Models\Cycle;
use App\Models\Member;
use App\Models\PaymentIntent;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PaymentIntent> */
class PaymentIntentFactory extends Factory
{
    protected $model = PaymentIntent::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $purpose = PaymentPurpose::SavingsContribution;

        return [
            'cycle_id' => Cycle::factory(),
            'member_id' => Member::factory(),
            'direction' => $purpose->direction(),
            'purpose' => $purpose,
            'channel' => PaymentChannel::MobileMoney,
            'amount_ngwee' => 50_000,
            'fee_bearer' => FeeBearer::Customer,
            'reference' => 'usg-tst-'.fake()->unique()->numerify('sav-#####-1'),
            'status' => PaymentStatus::Draft,
            'attempt' => 1,
        ];
    }

    public function forPurpose(PaymentPurpose $purpose): static
    {
        return $this->state(['purpose' => $purpose, 'direction' => $purpose->direction()]);
    }

    public function withStatus(PaymentStatus $status): static
    {
        return $this->state([
            'status' => $status,
            'initiated_at' => $status === PaymentStatus::Draft ? null : now(),
            'completed_at' => $status->hasSucceeded() ? now() : null,
        ]);
    }

    public function successful(): static
    {
        return $this->withStatus(PaymentStatus::Successful)->state([
            'provider_id' => fake()->uuid(),
            'provider_reference' => fake()->numerify('24073####'),
            'fee_ngwee' => 850,
        ]);
    }

    public function ofKwacha(int $kwacha): static
    {
        return $this->state(['amount_ngwee' => $kwacha * 100]);
    }
}
