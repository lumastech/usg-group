<?php

namespace App\Domain\Payouts;

/**
 * The workbook's ROUNDOFF ADJSTMNT column.
 *
 * Village-banking share-outs are usually paid in notes, so the workbook shaves each
 * member's net value down to a payable figure and keeps the difference. The group has
 * not settled on a convention yet, so NoRounding is bound and net payable equals net
 * value exactly. Everything downstream — the payouts row, the statement line, the
 * voucher, the remainder posting — already carries the adjustment, so adopting a
 * convention later is a change of binding in AppServiceProvider and nothing else.
 */
interface RoundingPolicy
{
    /**
     * The adjustment to add to a net value to reach the net payable figure.
     *
     * Negative shaves the member down and leaves the difference with the group;
     * positive tops them up out of it. Zero means no adjustment applies.
     */
    public function adjustmentFor(int $netValueNgwee): int;

    /** How the adjustment is explained on the statement and the voucher. */
    public function describe(): string;
}
