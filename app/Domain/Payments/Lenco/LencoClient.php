<?php

namespace App\Domain\Payments\Lenco;

use App\Exceptions\PaymentGatewayException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * The HTTP half of the Lenco integration: auth, the envelope, and the error codes.
 *
 * Everything the provider returns is wrapped in `{status, message, data}`, and a
 * failed charge still comes back as HTTP 200 — so the status flag is checked on every
 * response, not just the status line. Reads are retried; writes are not, because a
 * retried POST is how a member gets debited twice.
 */
class LencoClient
{
    public function __construct(protected HttpFactory $http) {}

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        return $this->envelope($path, $query)['data'];
    }

    /**
     * A listing, with the pagination block the provider sends beside it.
     *
     * @param  array<string, mixed>  $query
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function getPage(string $path, array $query = []): array
    {
        $envelope = $this->envelope($path, $query);

        return [
            'data' => array_values(array_filter($envelope['data'], 'is_array')),
            'meta' => $envelope['meta'],
        ];
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array{data: array<mixed>, meta: array<string, mixed>}
     */
    protected function envelope(string $path, array $query): array
    {
        return $this->unwrap(
            $this->request(retry: true)->get($this->url($path), $query),
            $path,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function post(string $path, array $payload = []): array
    {
        return $this->unwrap(
            $this->request(retry: false)->post($this->url($path), array_filter(
                $payload,
                fn (mixed $value): bool => $value !== null,
            )),
            $path,
        )['data'];
    }

    /** Whether the integration has enough configuration to be used at all. */
    public function isConfigured(): bool
    {
        return $this->token() !== '' && $this->config('base_url') !== '';
    }

    public function accountId(): string
    {
        $accountId = $this->config('account_id');

        if ($accountId === '') {
            throw new PaymentGatewayException(
                'No Lenco account id is configured, so the group has no account to send money from.'
            );
        }

        return $accountId;
    }

    public function country(): string
    {
        return $this->config('country') ?: 'zm';
    }

    public function currency(): string
    {
        return $this->config('currency') ?: 'ZMW';
    }

    public function config(string $key, string $default = ''): string
    {
        return (string) (config("payments.gateways.lenco.{$key}") ?? $default);
    }

    protected function token(): string
    {
        return $this->config('api_token');
    }

    protected function url(string $path): string
    {
        return rtrim($this->config('base_url'), '/').'/'.ltrim($path, '/');
    }

    protected function request(bool $retry): PendingRequest
    {
        if ($this->token() === '') {
            throw new PaymentGatewayException(
                'No Lenco API token is configured. Set LENCO_API_TOKEN before moving money.'
            );
        }

        $request = $this->http
            ->withToken($this->token())
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('payments.gateways.lenco.timeout', 30));

        if ($retry) {
            $request = $request->retry(
                (int) config('payments.gateways.lenco.retry_times', 2),
                (int) config('payments.gateways.lenco.retry_sleep_ms', 500),
                throw: false,
            );
        }

        return $request;
    }

    /**
     * Checks the envelope, or turns the failure into something the screens can say.
     *
     * @return array{data: array<mixed>, meta: array<string, mixed>}
     */
    protected function unwrap(Response $response, string $path): array
    {
        $body = $this->decode($response, $path);

        if ($response->failed() || ($body['status'] ?? false) !== true) {
            throw new PaymentGatewayException(
                $this->messageFrom($body, $response),
                errorCode: isset($body['errorCode']) ? (string) $body['errorCode'] : null,
                httpStatus: $response->status(),
                context: ['path' => $path, 'errors' => $body['errors'] ?? null],
            );
        }

        $data = $body['data'] ?? [];
        $meta = $body['meta'] ?? [];

        return [
            'data' => is_array($data) ? $data : [],
            'meta' => is_array($meta) ? $meta : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function decode(Response $response, string $path): array
    {
        try {
            $body = $response->json();
        } catch (ConnectionException $exception) {
            throw new PaymentGatewayException(
                'Could not reach the payment provider.',
                httpStatus: null,
                context: ['path' => $path],
                previous: $exception,
            );
        }

        return is_array($body) ? $body : [];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function messageFrom(array $body, Response $response): string
    {
        $message = isset($body['message']) && is_string($body['message']) && $body['message'] !== ''
            ? $body['message']
            : null;

        return $message ?? "The payment provider returned HTTP {$response->status()}.";
    }
}
