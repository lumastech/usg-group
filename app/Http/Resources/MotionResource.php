<?php

namespace App\Http\Resources;

use App\Domain\Governance\MotionRecorder;
use App\Models\Motion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A motion, with the arithmetic the screen shows beside its tally.
 *
 * A decided motion carries the base it was measured against as it was recorded that
 * day; an undecided one carries what deciding it would need right now. The two are
 * different figures on purpose — the first is history, the second is a live count.
 *
 * @mixin Motion
 */
class MotionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $requirement = app(MotionRecorder::class)->requirement($this->resource);

        return [
            'id' => $this->id,
            'meeting_id' => $this->meeting_id,
            'type' => $this->type,
            'type_label' => $this->type->label(),
            'subject' => $this->subject,
            'target_member_id' => $this->target_member_id,
            'target_name' => $this->whenLoaded('target', fn (): ?string => $this->target?->full_name),
            'proposed_by_member_id' => $this->proposed_by_member_id,
            'proposed_by_name' => $this->whenLoaded('proposedBy', fn (): ?string => $this->proposedBy?->full_name),
            'votes_for' => $this->votes_for,
            'votes_against' => $this->votes_against,
            'abstentions' => $this->abstentions,
            'threshold_basis' => $this->threshold_basis,
            'threshold_basis_label' => $this->threshold_basis->label(),
            'eligible_count' => $this->eligible_count,
            'votes_needed' => $this->votes_needed,
            'passed' => $this->passed,
            'decided_at' => $this->decided_at?->toIso8601String(),
            'is_decided' => $this->isDecided(),
            /* As recorded, for a decided motion; null until then. */
            'threshold_explanation' => $this->thresholdExplanation(),
            /* What it would take right now, for one still to be put. */
            'requirement' => $requirement,
            'amendment' => $this->whenLoaded('amendment', fn (): ?array => $this->amendment === null ? null : [
                'section_reference' => $this->amendment->section_reference,
                'current_text' => $this->amendment->current_text,
                'proposed_text' => $this->amendment->proposed_text,
                'effective_date' => $this->amendment->effective_date->toDateString(),
            ]),
            'abilities' => [
                'decide' => $request->user()->can('decide', $this->resource) && $requirement['can_decide'],
            ],
        ];
    }
}
