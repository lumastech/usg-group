<?php

namespace App\Exceptions;

use App\Models\Member;

/** Raised when anything is posted against a member whose payout has been executed. */
class MemberLedgersFrozenException extends DomainRuleException
{
    public static function for(Member $member): self
    {
        return new self(
            "{$member->full_name} was paid out on "
            .$member->ledgers_frozen_at->format('j F Y')
            .' and their ledgers are closed. Correct a settled position with a reversing entry on a reopened closure, not a new posting.'
        );
    }
}
