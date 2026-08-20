<?php

namespace App\Domain\Payouts;

use App\Exceptions\MemberLedgersFrozenException;
use App\Models\Member;
use Illuminate\Support\Carbon;

/**
 * The gate every ledger asks before it writes against a member.
 *
 * Executing a payout settles a position computed from the savings, loan and fund
 * ledgers. If any of them could still move afterwards, the voucher in the member's
 * hand would stop agreeing with the books, so the freeze is enforced at the point of
 * writing rather than trusted to nobody trying.
 */
class LedgerFreeze
{
    /** Closes a member's ledgers. Idempotent: an already frozen member keeps their date. */
    public function freeze(Member $member): Member
    {
        if ($member->ledgersFrozen()) {
            return $member;
        }

        $member->forceFill(['ledgers_frozen_at' => Carbon::now()])->save();

        return $member;
    }

    /** Reopens them, which only reversing a settlement should ever do. */
    public function thaw(Member $member): Member
    {
        $member->forceFill(['ledgers_frozen_at' => null])->save();

        return $member;
    }

    /** @throws MemberLedgersFrozenException */
    public function assertOpen(?Member $member): void
    {
        if ($member !== null && $member->ledgersFrozen()) {
            throw MemberLedgersFrozenException::for($member);
        }
    }
}
