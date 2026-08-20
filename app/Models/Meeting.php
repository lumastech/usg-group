<?php

namespace App\Models;

use App\Enums\MeetingType;
use App\Models\Concerns\BelongsToCycle;
use Database\Factories\MeetingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A gathering of the group, and the register taken at it.
 *
 * Attendance is a plain set of members — the register is worked on a phone in the
 * room, tapping names as people arrive — and quorum is read off it rather than
 * stored, so the ring on screen moves as the room fills. What does get frozen is the
 * base each motion was decided against; see App\Models\Motion.
 *
 * @property int $id
 * @property int $cycle_id
 * @property Carbon $meeting_date
 * @property MeetingType $type
 * @property string|null $subject
 * @property string|null $notes
 */
#[Fillable(['cycle_id', 'meeting_date', 'type', 'subject', 'notes'])]
class Meeting extends Model
{
    /** @use HasFactory<MeetingFactory> */
    use BelongsToCycle, HasFactory, LogsActivity;

    /** @return BelongsToMany<Member, $this> */
    public function attendees(): BelongsToMany
    {
        return $this->belongsToMany(Member::class, 'meeting_attendances')->withTimestamps();
    }

    /** @return HasMany<Motion, $this> */
    public function motions(): HasMany
    {
        return $this->hasMany(Motion::class);
    }

    public function label(): string
    {
        return $this->type->label().' — '.$this->meeting_date->format('j M Y');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontLogEmptyChanges();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'meeting_date' => 'date',
            'type' => MeetingType::class,
        ];
    }
}
