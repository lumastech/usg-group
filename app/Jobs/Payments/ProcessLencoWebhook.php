<?php

namespace App\Jobs\Payments;

use App\Domain\Payments\Lenco\LencoReference;
use App\Domain\Payments\PaymentGateway;
use App\Domain\Payments\PaymentIntentService;
use App\Models\LencoWebhookEvent;
use App\Models\PaymentIntent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turns one stored webhook into a change of status on the payment it is about.
 *
 * Deliberately does not trust the event's own figures for anything that decides
 * money: it takes the reference, asks the provider what that payment's state actually
 * is, and records that. A webhook is a nudge, not evidence — and re-querying is the
 * same call the poller makes, so there is one path to be right about rather than two.
 */
class ProcessLencoWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $eventId) {}

    public function handle(PaymentIntentService $intents, PaymentGateway $gateway): void
    {
        $event = LencoWebhookEvent::find($this->eventId);

        if ($event === null || $event->processed_at !== null) {
            return;
        }

        $reference = $event->reference;

        if ($reference === null || ! LencoReference::isOurs($reference)) {
            /*
             * Raised on the provider's dashboard rather than by us — a manual transfer,
             * somebody's test. Kept for the record and otherwise left alone.
             */
            $event->markProcessed();

            return;
        }

        $intent = PaymentIntent::query()->acrossCycles()->where('reference', $reference)->first();

        if ($intent === null) {
            $event->markFailed("No payment in this system carries the reference {$reference}.");

            return;
        }

        try {
            $result = $intent->isCollection()
                ? $gateway->collectionStatus($reference)
                : $gateway->transferStatus($reference);

            $intents->apply($intent, $result);

            if ($result->hasSucceeded()) {
                PostSettledPayment::dispatch($intent->id);
            }

            $event->markProcessed();
        } catch (Throwable $exception) {
            $event->markFailed($exception->getMessage());

            Log::warning('Lenco webhook could not be processed', [
                'event_id' => $event->id,
                'reference' => $reference,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }
}
