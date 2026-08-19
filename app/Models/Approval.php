<?php

namespace App\Models;

use App\Enums\ApprovalStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Two-person integrity record for a sensitive action such as a loan approval or a payout.
 *
 * @property int $id
 * @property string $action
 * @property int $requested_by_member_id
 * @property int|null $first_approver_member_id
 * @property int|null $second_approver_member_id
 * @property Carbon|null $first_approved_at
 * @property Carbon|null $second_approved_at
 * @property Carbon|null $rejected_at
 * @property ApprovalStatus $status
 * @property string|null $note
 */
#[Fillable([
    'approvable_type', 'approvable_id', 'action', 'requested_by_member_id',
    'first_approver_member_id', 'second_approver_member_id', 'first_approved_at',
    'second_approved_at', 'rejected_at', 'rejected_by_member_id', 'status', 'note',
])]
class Approval extends Model
{
    use LogsActivity;

    /** @return MorphTo<Model, $this> */
    public function approvable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<Member, $this> */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'requested_by_member_id');
    }

    /** @return BelongsTo<Member, $this> */
    public function firstApprover(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'first_approver_member_id');
    }

    /** @return BelongsTo<Member, $this> */
    public function secondApprover(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'second_approver_member_id');
    }

    public function isApproved(): bool
    {
        return $this->status === ApprovalStatus::Approved;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontLogEmptyChanges();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'first_approved_at' => 'datetime',
            'second_approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'status' => ApprovalStatus::class,
        ];
    }
}
