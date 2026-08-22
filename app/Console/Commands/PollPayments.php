<?php

namespace App\Console\Commands;

use App\Domain\Payments\PaymentIntentService;
use App\Domain\Payments\PaymentPoster;
use App\Enums\PaymentStatus;
use App\Exceptions\PaymentGatewayException;
use App\Models\PaymentIntent;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Asks the provider what became of every payment still in flight, and takes up any
 * money the ledgers have not yet been able to accept.
 *
 * Webhooks are the fast path, not the reliable one: the provider's own documentation
 * says to re-query, because a webhook sent while this server was down is a webhook
 * nobody hears. Everything here is idempotent — a payment the webhook already settled
 * is polled once more, agrees with itself, and nothing happens twice.
 *
 * It also does the quiet job of picking up savings that arrived outside a trading
 * window: those wait at Settled, and the first run after a session opens puts them on
 * the sheet.
 */
class PollPayments extends Command
{
    protected $signature = 'unity:poll-payments
        {--force : Ask about every payment in flight, ignoring how recently it was asked about}';

    protected $description = 'Ask the payment provider about payments still in flight, and post any that have settled';

    public function handle(PaymentIntentService $intents, PaymentPoster $poster): int
    {
        $asked = $this->pollInFlight($intents);
        $posted = $this->postSettled($poster);
        $expired = $this->expireStale();

        $this->components->info(
            "Asked about {$asked} payment(s), posted {$posted}, gave up on {$expired}."
        );

        return self::SUCCESS;
    }

    /** Everything the provider has not yet given us an outcome for. */
    protected function pollInFlight(PaymentIntentService $intents): int
    {
        $asked = 0;

        foreach ($this->dueForPolling() as $intent) {
            try {
                $intents->refresh($intent);
                $asked++;
            } catch (PaymentGatewayException $exception) {
                /*
                 * A provider that cannot be reached is not news about the payment. The
                 * attempt is still counted so a permanently unreachable payment ages out
                 * rather than being asked about forever.
                 */
                $intent->forceFill([
                    'last_polled_at' => Carbon::now(),
                    'poll_attempts' => $intent->poll_attempts + 1,
                ])->save();

                $this->components->warn("{$intent->reference}: {$exception->reason()}");
            }
        }

        return $asked;
    }

    /**
     * Money the provider moved that the ledgers have not taken yet.
     *
     * Mostly savings waiting for a trading session. Anything the ledger refuses lands
     * at NeedsAttention and is not seen here again.
     */
    protected function postSettled(PaymentPoster $poster): int
    {
        $posted = 0;

        PaymentIntent::query()
            ->acrossCycles()
            ->unposted()
            ->orderBy('id')
            ->chunkById(100, function ($intents) use ($poster, &$posted): void {
                foreach ($intents as $intent) {
                    if ($poster->post($intent)) {
                        $posted++;
                    }
                }
            });

        return $posted;
    }

    /**
     * Stops asking about payments that were never going to answer.
     *
     * A collection nobody approved is abandoned — the member walked away from their
     * handset, and no money moved. A transfer is never abandoned: money may have left
     * the group's account, so an unanswered one is escalated for somebody to go and
     * look at the provider's dashboard.
     */
    protected function expireStale(): int
    {
        $expired = 0;

        $collectionCutoff = Carbon::now()->subMinutes(
            (int) config('payments.collections.poll.give_up_after_minutes', 60)
        );

        $transferCutoff = Carbon::now()->subHours(
            (int) config('payments.transfers.poll.give_up_after_hours', 24)
        );

        foreach ($this->inFlight()->get() as $intent) {
            $started = $intent->initiated_at ?? $intent->created_at;

            if ($intent->isCollection() && $started?->lessThan($collectionCutoff)) {
                $intent->forceFill([
                    'status' => PaymentStatus::Abandoned,
                    'status_reason' => $intent->status_reason ?? 'Nobody approved this payment in time.',
                ])->save();
                $expired++;

                continue;
            }

            if ($intent->isTransfer() && $started?->lessThan($transferCutoff)) {
                $intent->forceFill([
                    'status' => PaymentStatus::NeedsAttention,
                    'status_reason' => 'The provider never said whether this transfer went through. Check the Lenco dashboard.',
                ])->save();
                $expired++;
            }
        }

        return $expired;
    }

    /** @return Collection<int, PaymentIntent> */
    protected function dueForPolling()
    {
        $query = $this->inFlight();

        if (! $this->option('force')) {
            $collectionAge = Carbon::now()->subMinutes(
                (int) config('payments.collections.poll.every_minutes', 5)
            );
            $transferAge = Carbon::now()->subMinutes(
                (int) config('payments.transfers.poll.every_minutes', 15)
            );

            $query->where(function ($builder) use ($collectionAge, $transferAge): void {
                $builder
                    ->whereNull('last_polled_at')
                    ->orWhere(fn ($inner) => $inner->collections()->where('last_polled_at', '<', $collectionAge))
                    ->orWhere(fn ($inner) => $inner->transfers()->where('last_polled_at', '<', $transferAge));
            });
        }

        return $query->orderBy('last_polled_at')->limit(200)->get();
    }

    /** @return Builder<PaymentIntent> */
    protected function inFlight()
    {
        return PaymentIntent::query()->acrossCycles()->awaitingOutcome();
    }
}
