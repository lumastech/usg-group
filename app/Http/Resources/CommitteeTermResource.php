<?php

namespace App\Http\Resources;

use App\Domain\Governance\CommitteeTermService;
use App\Models\CommitteeTerm;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One spell in office, for the committee cards and the term-history timeline.
 *
 * @mixin CommitteeTerm
 */
class CommitteeTermResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $terms = app(CommitteeTermService::class);

        return [
            'id' => $this->id,
            'member_id' => $this->member_id,
            'member_name' => $this->whenLoaded('member', fn (): string => $this->member->full_name),
            'member_number' => $this->whenLoaded('member', fn (): int => $this->member->member_number),
            'role' => $this->role,
            'role_label' => $this->role->label(),
            'portal_role' => $this->role->portalRole()?->value,
            'started_at' => $this->started_at->toDateString(),
            'ended_at' => $this->ended_at?->toDateString(),
            'end_reason' => $this->end_reason,
            'end_reason_label' => $this->end_reason?->label(),
            'resignation_notice_date' => $this->resignation_notice_date?->toDateString(),
            'earliest_resignation_date' => $this->earliestResignationDate()?->toDateString(),
            'notice_waiver_note' => $this->notice_waiver_note,
            'is_current' => $this->isCurrent(),
            'expires_on' => $terms->expiresOn($this->resource)->toDateString(),
            'is_overdue' => $terms->isOverdue($this->resource),
            'abilities' => [
                'end' => $request->user()->can('end', $this->resource),
            ],
        ];
    }
}
