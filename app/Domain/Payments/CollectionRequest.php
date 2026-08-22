<?php

namespace App\Domain\Payments;

use App\Enums\FeeBearer;
use App\Enums\MobileMoneyOperator;
use App\Models\PaymentIntent;

/**
 * One request for money from a member, as the gateway will be handed it.
 *
 * Built from an intent rather than from a form, so what is sent to the provider is
 * always what was written down first — if the process dies mid-call there is a row to
 * poll against.
 */
class CollectionRequest
{
    public function __construct(
        public readonly string $reference,
        public readonly int $amountNgwee,
        public readonly ?string $phone = null,
        public readonly ?MobileMoneyOperator $operator = null,
        public readonly ?string $email = null,
        public readonly ?string $firstName = null,
        public readonly ?string $lastName = null,
        public readonly FeeBearer $bearer = FeeBearer::Customer,
    ) {}

    public static function from(PaymentIntent $intent, ?string $phone = null, ?MobileMoneyOperator $operator = null): self
    {
        $member = $intent->member;
        $names = str($member->full_name ?? '')->squish()->explode(' ');

        return new self(
            reference: $intent->reference,
            amountNgwee: $intent->amount_ngwee->getMinorAmount()->toInt(),
            phone: $phone ?? $member?->phone,
            operator: $operator,
            email: $member?->user?->email,
            firstName: $names->first() ?: null,
            lastName: $names->count() > 1 ? $names->last() : null,
            bearer: $intent->fee_bearer ?? FeeBearer::Customer,
        );
    }
}
