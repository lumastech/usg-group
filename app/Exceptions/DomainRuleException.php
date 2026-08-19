<?php

namespace App\Exceptions;

use RuntimeException;

/** Raised when an action would violate one of the group's constitution rules. */
class DomainRuleException extends RuntimeException
{
    public static function make(string $message): self
    {
        return new self($message);
    }
}
