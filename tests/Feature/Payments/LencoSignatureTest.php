<?php

use App\Domain\Payments\Lenco\LencoSignature;

it('accepts a signature computed the way the provider computes it', function (): void {
    $body = '{"event":"collection.successful","data":{"reference":"usg-sav-00001-1"}}';
    $token = 'test-token';
    $signature = hash_hmac('sha512', $body, hash('sha256', $token));

    expect(LencoSignature::verify($body, $signature, $token))->toBeTrue();
});

it('rejects a body that was altered after it was signed', function (): void {
    $token = 'test-token';
    $signature = LencoSignature::expected('{"amount":"10.00"}', $token);

    expect(LencoSignature::verify('{"amount":"10000.00"}', $signature, $token))->toBeFalse();
});

it('rejects a signature made with a different token', function (): void {
    $body = '{"event":"transfer.successful"}';

    expect(LencoSignature::verify($body, LencoSignature::expected($body, 'other-token'), 'test-token'))->toBeFalse();
});

it('rejects an unsigned request, and one we have no token to check', function (): void {
    expect(LencoSignature::verify('{}', null, 'test-token'))->toBeFalse()
        ->and(LencoSignature::verify('{}', '', 'test-token'))->toBeFalse()
        ->and(LencoSignature::verify('{}', 'anything', null))->toBeFalse()
        ->and(LencoSignature::verify('{}', 'anything', ''))->toBeFalse();
});

it('is computed over the body exactly as it arrived', function (): void {
    $token = 'test-token';
    $arrived = '{"b":1,"a":2}';

    // Re-encoding the decoded payload reorders nothing here but reformats it, which is
    // enough to change the digest — this is why the raw body is what gets signed.
    expect(LencoSignature::expected($arrived, $token))
        ->not->toBe(LencoSignature::expected(json_encode(json_decode($arrived, true), JSON_PRETTY_PRINT), $token));
});
