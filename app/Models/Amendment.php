<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCycle;
use Database\Factories\AmendmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A proposed change to the constitution, hanging off the motion that carries it.
 *
 * The old and new wording are both kept verbatim, so the log reads as a history of
 * the document rather than a list of decisions about it.
 *
 * @property int $id
 * @property int $cycle_id
 * @property int $motion_id
 * @property string $section_reference
 * @property string $current_text
 * @property string $proposed_text
 * @property Carbon $effective_date
 */
#[Fillable([
    'cycle_id', 'motion_id', 'section_reference', 'current_text', 'proposed_text', 'effective_date',
])]
class Amendment extends Model
{
    /** @use HasFactory<AmendmentFactory> */
    use BelongsToCycle, HasFactory, LogsActivity;

    /** @return BelongsTo<Motion, $this> */
    public function motion(): BelongsTo
    {
        return $this->belongsTo(Motion::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontLogEmptyChanges();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'effective_date' => 'date',
        ];
    }
}
