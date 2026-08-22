<?php

namespace App\Domain\Payments\Lenco;

use App\Enums\PaymentPurpose;
use App\Models\PaymentIntent;

/**
 * The reference we put on every payment, and read back off every webhook.
 *
 * The provider refuses a duplicate, which is the outer edge of our idempotency: a
 * retry is a new intent with a new reference, never the same one sent twice. The
 * prefix is per environment so a reference minted while testing in the sandbox can
 * never collide with a real one.
 *
 * Shape: usg-sav-00412-1 — prefix, purpose, intent, attempt.
 */
final class LencoReference
{
    /** The provider allows only these characters. */
    public const PATTERN = '/^[A-Za-z0-9._-]{1,64}$/';

    public static function for(PaymentIntent $intent): string
    {
        return self::build($intent->purpose, $intent->id, $intent->attempt);
    }

    public static function build(PaymentPurpose $purpose, int $intentId, int $attempt = 1): string
    {
        return implode('-', [
            self::prefix(),
            $purpose->referenceCode(),
            str_pad((string) $intentId, 5, '0', STR_PAD_LEFT),
            $attempt,
        ]);
    }

    /**
     * Pulls our own reference apart again.
     *
     * Returns null for anything we did not mint — the provider's dashboard can raise
     * transactions of its own, and those are not ours to match.
     *
     * @return array{prefix: string, code: string, intent_id: int, attempt: int}|null
     */
    public static function parse(string $reference): ?array
    {
        $prefix = self::prefix();

        if (! str_starts_with($reference, $prefix.'-')) {
            return null;
        }

        $parts = explode('-', mb_substr($reference, mb_strlen($prefix) + 1));

        if (count($parts) !== 3 || ! ctype_digit($parts[1]) || ! ctype_digit($parts[2])) {
            return null;
        }

        return [
            'prefix' => $prefix,
            'code' => $parts[0],
            'intent_id' => (int) $parts[1],
            'attempt' => (int) $parts[2],
        ];
    }

    public static function isOurs(string $reference): bool
    {
        return self::parse($reference) !== null;
    }

    public static function isValid(string $reference): bool
    {
        return (bool) preg_match(self::PATTERN, $reference);
    }

    private static function prefix(): string
    {
        return (string) config('payments.reference_prefix', 'usg');
    }
}
