<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * The provider refused the request, or could not be reached.
 *
 * `errorCode` is the provider's own — 01 validation, 02 insufficient funds, 04
 * duplicate reference, 05 invalid recipient — and is carried through so the screens
 * can say something better than "payment failed".
 */
class PaymentGatewayException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $errorCode = null,
        public readonly ?int $httpStatus = null,
        /** @var array<string, mixed> */
        public readonly array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** Whether trying the same thing again could plausibly work. */
    public function isRetryable(): bool
    {
        if ($this->errorCode !== null) {
            return in_array($this->errorCode, ['02', '03'], true);
        }

        return $this->httpStatus === null || $this->httpStatus >= 500;
    }

    /** Whether the provider has already seen this reference. */
    public function isDuplicateReference(): bool
    {
        return $this->errorCode === '04';
    }

    /** What a committee member should be shown. */
    public function reason(): string
    {
        return match ($this->errorCode) {
            '01' => 'The provider rejected the details: '.$this->getMessage(),
            '02' => 'There is not enough money in the group\'s Lenco account.',
            '03' => 'The account\'s transfer limit has been reached.',
            '04' => 'This payment reference has already been used.',
            '05' => 'The receiving account was not accepted.',
            '06' => 'The group\'s account is restricted from sending money.',
            default => $this->getMessage(),
        };
    }
}
