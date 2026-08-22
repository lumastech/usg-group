<?php

namespace App\Jobs\Payments;

use App\Domain\Payments\PaymentPoster;
use App\Models\PaymentIntent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Hands a settled payment to the ledgers.
 *
 * Safe to run twice, three times, or from two workers at once: PaymentPoster claims
 * the payment with a conditional UPDATE, so the loser of the race does nothing.
 */
class PostSettledPayment implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $paymentIntentId) {}

    public function handle(PaymentPoster $poster): void
    {
        $intent = PaymentIntent::query()->acrossCycles()->find($this->paymentIntentId);

        if ($intent === null) {
            return;
        }

        $poster->post($intent);
    }
}
