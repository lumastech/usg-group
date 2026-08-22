<?php

namespace App\Models;

use Database\Factories\LencoWebhookEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A webhook exactly as it arrived, kept before anything is done with it.
 *
 * The provider retries every 30 minutes for 24 hours until it sees a 2xx, so the same
 * event will arrive again — the unique key on `event_key` is what makes the second
 * delivery a cheap no-op instead of a second ledger entry. Nothing here is trusted
 * until the signature has been checked.
 *
 * @property int $id
 * @property string $event
 * @property string $event_key
 * @property string|null $reference
 * @property string|null $signature
 * @property array<string, mixed> $payload
 * @property Carbon $received_at
 * @property Carbon|null $processed_at
 * @property string|null $error
 */
#[Fillable(['event', 'event_key', 'reference', 'signature', 'payload', 'received_at', 'processed_at', 'error'])]
class LencoWebhookEvent extends Model
{
    /** @use HasFactory<LencoWebhookEventFactory> */
    use HasFactory;

    /** @param Builder<static> $query */
    public function scopeUnprocessed(Builder $query): void
    {
        $query->whereNull('processed_at');
    }

    public function markProcessed(): void
    {
        $this->forceFill(['processed_at' => Carbon::now(), 'error' => null])->save();
    }

    public function markFailed(string $error): void
    {
        $this->forceFill(['error' => $error])->save();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}
