<?php

namespace App\Http\Resources;

use App\Models\Amendment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One entry in the amendment log: the wording before, the wording after, and how the
 * group voted on the change.
 *
 * @mixin Amendment
 */
class AmendmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'motion_id' => $this->motion_id,
            'section_reference' => $this->section_reference,
            'current_text' => $this->current_text,
            'proposed_text' => $this->proposed_text,
            'effective_date' => $this->effective_date->toDateString(),
            'motion' => $this->whenLoaded('motion', fn (): array => [
                'id' => $this->motion->id,
                'subject' => $this->motion->subject,
                'meeting_id' => $this->motion->meeting_id,
                'votes_for' => $this->motion->votes_for,
                'votes_against' => $this->motion->votes_against,
                'abstentions' => $this->motion->abstentions,
                'eligible_count' => $this->motion->eligible_count,
                'votes_needed' => $this->motion->votes_needed,
                'threshold_explanation' => $this->motion->thresholdExplanation(),
                'passed' => $this->motion->passed,
                'is_decided' => $this->motion->isDecided(),
                'decided_at' => $this->motion->decided_at?->toIso8601String(),
            ]),
        ];
    }
}
