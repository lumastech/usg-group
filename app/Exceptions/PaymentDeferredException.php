<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The money is fine; the group is not ready to record it yet.
 *
 * Raised when a savings payment lands outside a trading window. Nothing is wrong and
 * nobody needs to be told — the payment waits at Settled and is taken up by the sheet
 * the next time a session opens.
 */
class PaymentDeferredException extends RuntimeException
{
    public static function make(string $message): self
    {
        return new self($message);
    }
}
