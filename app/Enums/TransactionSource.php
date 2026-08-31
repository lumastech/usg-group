<?php

namespace App\Enums;

enum TransactionSource: string
{
    case Manual = 'manual';
    case Trading = 'trading';
    case Import = 'import';
    case System = 'system';

    /** Money that arrived through the payment gateway rather than across the table. */
    case Gateway = 'gateway';

    /**
     * Banknotes counted by a treasurer.
     *
     * Distinct from Manual, which is a committee member typing a figure into a ledger.
     * A wallet credited from Cash is the one movement with no provider record behind
     * it, so it is named rather than left to look like a gateway payment — see
     * `unity:reconcile-wallets`, which has to know how much of the float is in the tin.
     */
    case Cash = 'cash';
}
