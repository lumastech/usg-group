<?php

namespace App\Domain\Payments;

/** One bank or financial institution the provider can pay into. */
class BankOption
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?string $country = null,
    ) {}
}
