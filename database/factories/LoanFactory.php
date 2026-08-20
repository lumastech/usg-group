<?php

namespace Database\Factories;

use App\Enums\LoanStatus;
use App\Models\Cycle;
use App\Models\Loan;
use App\Models\Member;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/** @extends Factory<Loan> */
class LoanFactory extends Factory
{
    protected $model = Loan::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'cycle_id' => Cycle::factory(),
            'member_id' => Member::factory(),
            'principal_ngwee' => 1_000_000,
            'tenor_months' => 4,
            'schedule_compressed' => false,
            'status' => LoanStatus::Requested,
            'requested_at' => Carbon::parse('2026-01-02 09:00'),
            'current_balance_ngwee' => 0,
        ];
    }

    public function principal(int $kwacha): static
    {
        return $this->state(['principal_ngwee' => $kwacha * 100]);
    }

    public function approved(): static
    {
        return $this->state([
            'status' => LoanStatus::Approved,
            'approved_at' => Carbon::parse('2026-01-03 10:00'),
        ]);
    }

    public function disbursed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => LoanStatus::Disbursed,
            'approved_at' => Carbon::parse('2026-01-03 10:00'),
            'disbursed_at' => Carbon::parse('2026-01-07 10:00'),
            'disbursement_position' => 1,
            'current_balance_ngwee' => $attributes['principal_ngwee'],
        ]);
    }

    public function repaying(): static
    {
        return $this->disbursed()->state(['status' => LoanStatus::Repaying]);
    }

    public function defaulted(): static
    {
        return $this->disbursed()->state([
            'status' => LoanStatus::Defaulted,
            'defaulted_at' => Carbon::parse('2026-06-08 10:00'),
        ]);
    }

    public function settled(): static
    {
        return $this->state([
            'status' => LoanStatus::Settled,
            'settled_at' => Carbon::parse('2026-05-07 10:00'),
            'current_balance_ngwee' => 0,
        ]);
    }

    /** A second loan allowed by a committee member's written discretion. */
    public function withDiscretionOverride(string $note = 'School fees emergency, agreed at the January meeting.'): static
    {
        return $this->state(['discretion_override' => true, 'discretion_note' => $note]);
    }
}
