<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A record that one scheduled notification batch has already been sent.
 *
 * @property int $id
 * @property int|null $cycle_id
 * @property string $key
 * @property Carbon $sent_on
 * @property int $recipients
 */
#[Fillable(['cycle_id', 'key', 'sent_on', 'recipients'])]
class NotificationDispatch extends Model
{
    /** @return BelongsTo<Cycle, $this> */
    public function cycle(): BelongsTo
    {
        return $this->belongsTo(Cycle::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sent_on' => 'date',
        ];
    }
}
