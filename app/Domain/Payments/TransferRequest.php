<?php

namespace App\Domain\Payments;

use App\Enums\MobileMoneyOperator;
use App\Enums\PayoutDestinationType;
use App\Models\PaymentIntent;
use App\Models\PayoutDestination;

/**
 * One instruction to send money out.
 *
 * The destination is carried whole rather than as loose fields so nothing downstream
 * can send a bank account number down the mobile money endpoint.
 */
class TransferRequest
{
    public function __construct(
        public readonly string $reference,
        public readonly int $amountNgwee,
        public readonly PayoutDestinationType $type,
        public readonly ?string $narration = null,
        public readonly ?string $accountNumber = null,
        public readonly ?string $bankId = null,
        public readonly ?string $phone = null,
        public readonly ?MobileMoneyOperator $operator = null,
        public readonly ?string $providerRecipientId = null,
    ) {}

    public static function from(PaymentIntent $intent, PayoutDestination $destination, ?string $narration = null): self
    {
        return new self(
            reference: $intent->reference,
            amountNgwee: $intent->amount_ngwee->getMinorAmount()->toInt(),
            type: $destination->type,
            narration: $narration ?? $intent->purpose->label(),
            accountNumber: $destination->account_number,
            bankId: $destination->bank_id,
            phone: $destination->phone,
            operator: $destination->operator,
            providerRecipientId: $destination->provider_recipient_id,
        );
    }
}
