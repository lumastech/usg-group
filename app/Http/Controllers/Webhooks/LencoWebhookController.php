<?php

namespace App\Http\Controllers\Webhooks;

use App\Domain\Payments\Lenco\LencoSignature;
use App\Http\Controllers\Controller;
use App\Jobs\Payments\ProcessLencoWebhook;
use App\Models\LencoWebhookEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

/**
 * Takes a webhook and gets out of the way.
 *
 * Four things happen here and nothing else: check the signature, write the event
 * down, queue the work, answer 200. The provider retries every 30 minutes for 24 hours
 * on anything that is not a 2xx, and it will time out waiting on work done inline —
 * which it then reads as a failure and sends again. So the work is never done here.
 */
class LencoWebhookController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $raw = $request->getContent();

        if (! LencoSignature::verify($raw, $request->header(LencoSignature::HEADER), $this->apiToken())) {
            return response('', 401);
        }

        $payload = json_decode($raw, true);

        if (! is_array($payload) || ! isset($payload['event'])) {
            return response('', 400);
        }

        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $event = (string) $payload['event'];

        try {
            $record = LencoWebhookEvent::create([
                'event' => $event,
                'event_key' => $this->keyFor($event, $data, $raw),
                'reference' => isset($data['reference']) && is_string($data['reference'])
                    ? $data['reference']
                    : null,
                'signature' => $request->header(LencoSignature::HEADER),
                'payload' => $payload,
                'received_at' => Carbon::now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            /*
             * Already seen. The provider is simply retrying because a previous 200 did
             * not reach it, or reached it late. Acknowledging is the whole answer.
             */
            return response('', 200);
        }

        ProcessLencoWebhook::dispatch($record->id);

        return response('', 200);
    }

    /**
     * What makes this delivery the same delivery next time.
     *
     * The provider's transaction id plus the event name: one collection legitimately
     * raises `collection.successful` and then `collection.settled`, and those are two
     * events about one payment, not a duplicate.
     *
     * @param  array<string, mixed>  $data
     */
    protected function keyFor(string $event, array $data, string $raw): string
    {
        $id = $data['id'] ?? $data['reference'] ?? null;

        return is_string($id) && $id !== ''
            ? mb_substr($event.':'.$id, 0, 128)
            : mb_substr($event.':'.hash('sha256', $raw), 0, 128);
    }

    protected function apiToken(): ?string
    {
        $token = config('payments.gateways.lenco.api_token');

        return is_string($token) && $token !== '' ? $token : null;
    }
}
