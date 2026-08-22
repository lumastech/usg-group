<?php

namespace App\Domain\Payments;

use App\Enums\FeeBearer;
use App\Enums\PaymentStatus;
use Carbon\CarbonInterface;

/**
 * What the provider said, in this application's own terms.
 *
 * Every gateway method returns one of these, so nothing outside App\Domain\Payments\Lenco
 * ever reads a provider status string, a decimal amount or a camelCased key.
 */
class PaymentResult
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly PaymentStatus $status,
        public readonly ?string $providerId = null,
        public readonly ?string $providerReference = null,
        public readonly ?int $amountNgwee = null,
        public readonly ?int $feeNgwee = null,
        public readonly ?FeeBearer $feeBearer = null,
        public readonly ?CarbonInterface $initiatedAt = null,
        public readonly ?CarbonInterface $completedAt = null,
        public readonly ?CarbonInterface $settledAt = null,
        public readonly ?string $reasonForFailure = null,
        /** Where to send the member to finish a 3-D Secure authorisation. */
        public readonly ?string $authorizationUrl = null,
        /** The name on the account the money came from or went to. */
        public readonly ?string $accountName = null,
        public readonly array $raw = [],
    ) {}

    public function hasSucceeded(): bool
    {
        return $this->status->hasSucceeded();
    }

    public function hasFailed(): bool
    {
        return $this->status === PaymentStatus::Failed;
    }

    /** Whether the member still has something to do on their handset or in a browser. */
    public function needsAuthorization(): bool
    {
        return $this->status === PaymentStatus::AwaitingAuthorization;
    }
}
