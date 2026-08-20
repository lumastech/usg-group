<?php

namespace App\Models;

use App\Enums\MotionType;
use App\Enums\ThresholdBasis;
use App\Models\Concerns\BelongsToCycle;
use Database\Factories\MotionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Something put to the group, and the show of hands it drew.
 *
 * Only tallies are kept — nobody's individual vote is recorded, because the vote is
 * taken by raised hand. Deciding a motion snapshots the base it was measured against
 * and the number of votes that base required, so the minutes stay true no matter who
 * joins or leaves afterwards.
 *
 * @property int $id
 * @property int $cycle_id
 * @property int|null $meeting_id
 * @property MotionType $type
 * @property string $subject
 * @property int|null $target_member_id
 * @property int $proposed_by_member_id
 * @property int $votes_for
 * @property int $votes_against
 * @property int $abstentions
 * @property ThresholdBasis $threshold_basis
 * @property int|null $eligible_count
 * @property int|null $votes_needed
 * @property bool|null $passed
 * @property Carbon|null $decided_at
 */
#[Fillable([
    'cycle_id', 'meeting_id', 'type', 'subject', 'target_member_id', 'proposed_by_member_id',
    'votes_for', 'votes_against', 'abstentions', 'threshold_basis', 'eligible_count',
    'votes_needed', 'passed', 'decided_at',
])]
class Motion extends Model
{
    /** @use HasFactory<MotionFactory> */
    use BelongsToCycle, HasFactory, LogsActivity;

    /** @return BelongsTo<Meeting, $this> */
    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    /** @return BelongsTo<Member, $this> */
    public function target(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'target_member_id');
    }

    /** @return BelongsTo<Member, $this> */
    public function proposedBy(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'proposed_by_member_id');
    }

    /** @return HasOne<Amendment, $this> */
    public function amendment(): HasOne
    {
        return $this->hasOne(Amendment::class);
    }

    public function isDecided(): bool
    {
        return $this->decided_at !== null;
    }

    public function hasPassed(): bool
    {
        return $this->passed === true;
    }

    /** The show of hands, ignoring abstentions — they count towards neither side. */
    public function votesCast(): int
    {
        return $this->votes_for + $this->votes_against;
    }

    /**
     * The arithmetic, in the words the screen reads it back in.
     *
     * e.g. "needs 18 of 30 total active members". Shown beside the tally so nobody has
     * to take the pass or fail on trust.
     */
    public function thresholdExplanation(): ?string
    {
        if ($this->votes_needed === null || $this->eligible_count === null) {
            return null;
        }

        return sprintf(
            'needs %d of %d %s',
            $this->votes_needed,
            $this->eligible_count,
            $this->threshold_basis === ThresholdBasis::TotalMembers
                ? 'total active members'
                : 'members present',
        );
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontLogEmptyChanges();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => MotionType::class,
            'threshold_basis' => ThresholdBasis::class,
            'passed' => 'boolean',
            'decided_at' => 'datetime',
        ];
    }
}
