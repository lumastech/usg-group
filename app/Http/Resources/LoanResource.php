<?php

namespace App\Http\Resources;

use App\Models\Loan;
use App\Support\Kwacha;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One loan as the portal renders it.
 *
 * Money is always integer ngwee, and `abilities` carries the policy's real answers so
 * the action bar on the detail screen is the server's list, not the client's guess.
 *
 * @mixin Loan
 */
class LoanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $nextDue = $this->nextDueItem();

        return [
            'id' => $this->id,
            'member_id' => $this->member_id,
            'member_name' => $this->whenLoaded('member', fn (): string => $this->member->full_name),
            'member_number' => $this->whenLoaded('member', fn (): int => $this->member->member_number),
            'principal_ngwee' => Kwacha::toNgwee($this->principal_ngwee),
            'balance_ngwee' => Kwacha::toNgwee($this->current_balance_ngwee),
            'principal_outstanding_ngwee' => $this->principalOutstandingNgwee(),
            'interest_charged_ngwee' => $this->interestChargedNgwee(),
            'penalties_ngwee' => $this->penaltiesChargedNgwee(),
            'tenor_months' => $this->tenor_months,
            'schedule_compressed' => $this->schedule_compressed,
            'status' => $this->status,
            'status_label' => $this->status->label(),
            'requested_at' => $this->requested_at?->toIso8601String(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'approved_by' => $this->whenLoaded('approvedBy', fn (): ?string => $this->approvedBy?->full_name),
            'second_approver' => $this->whenLoaded('secondApprover', fn (): ?string => $this->secondApprover?->full_name),
            'disbursed_at' => $this->disbursed_at?->toIso8601String(),
            'disbursement_position' => $this->disbursement_position,
            'out_of_order_reason' => $this->out_of_order_reason,
            'settled_at' => $this->settled_at?->toIso8601String(),
            'defaulted_at' => $this->defaulted_at?->toIso8601String(),
            'discretion_override' => $this->discretion_override,
            'discretion_note' => $this->discretion_note,
            'rejection_reason' => $this->rejection_reason,

            'next_due_on' => $nextDue?->due_on->toDateString(),
            'next_due_ngwee' => $nextDue === null ? null : $nextDue->outstandingNgwee(),
            'days_late' => $this->daysLate($nextDue?->due_on),

            'abilities' => $this->abilities($request),
        ];
    }

    /** How overdue the next installment is today; zero when nothing is late. */
    protected function daysLate(?CarbonInterface $dueOn): int
    {
        if ($dueOn === null || ! $this->status->isOutstanding()) {
            return 0;
        }

        return max(0, (int) $dueOn->startOfDay()->diffInDays(now()->startOfDay(), false));
    }

    /**
     * @return array<string, bool>
     */
    protected function abilities(Request $request): array
    {
        $user = $request->user();

        if ($user === null) {
            return [];
        }

        return [
            'view' => $user->can('view', $this->resource),
            'approve' => $user->can('approve', $this->resource),
            'reject' => $user->can('reject', $this->resource),
            'disburse' => $user->can('disburse', $this->resource),
            'recordRepayment' => $user->can('recordRepayment', $this->resource),
            'markDefault' => $user->can('markDefault', $this->resource),
            'claimCollateral' => $user->can('claimCollateral', $this->resource),
        ];
    }
}
