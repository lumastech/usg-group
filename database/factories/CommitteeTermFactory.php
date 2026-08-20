<?php

namespace Database\Factories;

use App\Enums\CommitteeRole;
use App\Enums\TermEndReason;
use App\Models\CommitteeTerm;
use App\Models\Cycle;
use App\Models\Member;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/** @extends Factory<CommitteeTerm> */
class CommitteeTermFactory extends Factory
{
    protected $model = CommitteeTerm::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'cycle_id' => Cycle::factory(),
            'member_id' => Member::factory(),
            'role' => CommitteeRole::Chairperson,
            'started_at' => Carbon::parse('2025-12-01'),
        ];
    }

    public function role(CommitteeRole $role): static
    {
        return $this->state(['role' => $role]);
    }

    public function ended(TermEndReason $reason = TermEndReason::TermEnd, ?string $on = null): static
    {
        return $this->state([
            'ended_at' => Carbon::parse($on ?? '2026-11-30'),
            'end_reason' => $reason,
        ]);
    }
}
