<?php

namespace App\Domain\Payments\Lenco;

/**
 * Proves a webhook came from the provider.
 *
 * The signature is an HMAC-SHA512 of the raw request body, keyed by the SHA-256 of our
 * API token. It must be computed over the body exactly as it arrived — re-encoding the
 * decoded JSON changes key order and whitespace and produces a different digest, which
 * is the classic way this check ends up passing nothing or, worse, everything.
 */
final class LencoSignature
{
    public const HEADER = 'X-Lenco-Signature';

    public static function expected(string $rawBody, string $apiToken): string
    {
        return hash_hmac('sha512', $rawBody, hash('sha256', $apiToken));
    }

    public static function verify(string $rawBody, ?string $signature, ?string $apiToken): bool
    {
        if ($signature === null || $signature === '' || $apiToken === null || $apiToken === '') {
            return false;
        }

        return hash_equals(self::expected($rawBody, $apiToken), $signature);
    }
}
