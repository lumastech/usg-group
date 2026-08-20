<?php

namespace App\Models;

use App\Enums\CommitteeRole;
use App\Enums\TermEndReason;
use App\Models\Concerns\BelongsToCycle;
use Carbon\CarbonInterface;
use Database\Factories\CommitteeTermFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One member's spell in one office.
 *
 * A term is the only thing that puts somebody on the committee: the matching portal
 * role is granted for its duration and revoked when it ends, so the roles table is
 * always a reflection of this one and never something maintained beside it.
 *
 * @property int $id
 * @property int $cycle_id
 * @property int $member_id
 * @property CommitteeRole $role
 * @property Carbon $started_at
 * @property Carbon|null $ended_at
 * @property TermEndReason|null $end_reason
 * @property Carbon|null $resignation_notice_date
 * @property string|null $notice_waiver_note
 */
#[Fillable([
    'cycle_id', 'member_id', 'role', 'started_at', 'ended_at', 'end_reason',
    'resignation_notice_date', 'notice_waiver_note',
])]
class CommitteeTerm extends Model
{
    /** @use HasFactory<CommitteeTermFactory> */
    use BelongsToCycle, HasFactory, LogsActivity;

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** @param  Builder<CommitteeTerm>  $query */
    public function scopeCurrent(Builder $query): void
    {
        $query->whereNull('ended_at');
    }

    public function isCurrent(): bool
    {
        return $this->ended_at === null;
    }

    /**
     * The earliest day this term may end, given the notice served.
     *
     * The constitution asks an officer to serve one further month so the group is not
     * left without a signatory overnight. Null when no notice was given, which is the
     * case for a removal or a term simply running out.
     */
    public function earliestResignationDate(): ?CarbonInterface
    {
        return $this->resignation_notice_date?->copy()->addMonthNoOverflow();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontLogEmptyChanges();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'role' => CommitteeRole::class,
            'started_at' => 'date',
            'ended_at' => 'date',
            'end_reason' => TermEndReason::class,
            'resignation_notice_date' => 'date',
        ];
    }
}
