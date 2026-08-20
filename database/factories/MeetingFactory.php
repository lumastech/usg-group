<?php

namespace Database\Factories;

use App\Enums\MeetingType;
use App\Models\Cycle;
use App\Models\Meeting;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/** @extends Factory<Meeting> */
class MeetingFactory extends Factory
{
    protected $model = Meeting::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'cycle_id' => Cycle::factory(),
            'meeting_date' => Carbon::parse('2026-03-07'),
            'type' => MeetingType::Monthly,
        ];
    }

    public function type(MeetingType $type): static
    {
        return $this->state(['type' => $type]);
    }
}
