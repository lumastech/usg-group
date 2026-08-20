<?php

namespace App\Exceptions;

use App\Domain\Loans\LoanEligibility;

/**
 * Raised when a loan is requested or disbursed against a member who does not qualify.
 *
 * Carries the full eligibility result rather than a single message, so the screen that
 * caught it can render every failed condition instead of only the first one.
 */
class LoanNotEligibleException extends DomainRuleException
{
    protected LoanEligibility $eligibility;

    public static function from(LoanEligibility $eligibility): self
    {
        $exception = new self($eligibility->summary());
        $exception->eligibility = $eligibility;

        return $exception;
    }

    public function eligibility(): LoanEligibility
    {
        return $this->eligibility;
    }

    /** @return array<int, string> */
    public function reasons(): array
    {
        return $this->eligibility->reasonMessages();
    }
}
