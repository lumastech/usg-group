<?php

namespace App\Domain\Notifications;

use App\Models\Cycle;
use App\Models\NotificationDispatch;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\QueryException;

/**
 * The "send this once" guard behind every scheduled notification.
 *
 * The row is written before the notifications go out, not after. That ordering is
 * deliberate: if the process dies half way through a batch, the failure mode is
 * some members missing one reminder, rather than thirty members receiving the same
 * reminder twice the next time the scheduler runs. Duplicate money-adjacent mail is
 * the thing the group would actually complain about.
 *
 * The unique index on `key` is what makes this safe when two runs overlap — the
 * loser of the race takes the constraint violation and sends nothing.
 */
class NotificationDispatchLog
{
    /**
     * Run the sender unless this key has already been dispatched.
     *
     * @param  Closure(): int  $send
     * @return int|null recipients notified, or null when it had already gone out
     */
    public function once(?Cycle $cycle, string $key, CarbonInterface $on, Closure $send): ?int
    {
        $dispatch = $this->claim($cycle, $key, $on);

        if ($dispatch === null) {
            return null;
        }

        $recipients = $send();

        $dispatch->forceFill(['recipients' => $recipients])->save();

        return $recipients;
    }

    public function hasSent(string $key): bool
    {
        return NotificationDispatch::query()->where('key', $key)->exists();
    }

    /** Take the slot for this key, or return null if somebody already holds it. */
    protected function claim(?Cycle $cycle, string $key, CarbonInterface $on): ?NotificationDispatch
    {
        if ($this->hasSent($key)) {
            return null;
        }

        try {
            return NotificationDispatch::create([
                'cycle_id' => $cycle?->id,
                'key' => $key,
                'sent_on' => $on->toDateString(),
                'recipients' => 0,
            ]);
        } catch (QueryException) {
            return null;
        }
    }
}
