<?php

namespace App\Domain\Payments;

/**
 * The provider's answer to "whose account is this?".
 *
 * The name is the whole point: it is what gets compared to the member's own before the
 * group agrees to send money anywhere.
 */
class ResolvedAccount
{
    public function __construct(
        public readonly string $accountName,
        public readonly ?string $accountNumber = null,
        public readonly ?string $bankId = null,
        public readonly ?string $bankName = null,
        public readonly ?string $phone = null,
        public readonly ?string $operator = null,
    ) {}
}
