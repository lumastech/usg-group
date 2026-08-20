<?php

namespace App\Models;

use App\Enums\NextOfKinRelationship;
use Database\Factories\NextOfKinFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Someone a member nominated to be contacted, and paid out to, on their behalf.
 *
 * @property int $id
 * @property int $member_id
 * @property string $name
 * @property string|null $phone
 * @property NextOfKinRelationship $relationship
 * @property string|null $relationship_label
 */
#[Fillable(['member_id', 'name', 'phone', 'relationship', 'relationship_label'])]
class NextOfKin extends Model
{
    /** @use HasFactory<NextOfKinFactory> */
    use HasFactory, LogsActivity;

    protected $table = 'next_of_kin';

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** The wording the member gave, falling back to the bucket's own name. */
    public function relationshipLabel(): string
    {
        return $this->relationship_label ?: $this->relationship->label();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontLogEmptyChanges();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'relationship' => NextOfKinRelationship::class,
        ];
    }
}
