<?php

use App\Enums\FeeBearer;

return [

    /*
    |--------------------------------------------------------------------------
    | Withdrawals
    |--------------------------------------------------------------------------
    |
    | The committee's answers to docs/WALLET-PLAN.md §7, minuted 2026-08-31. They
    | live here rather than in the code because they are policy the group may
    | revisit at an AGM, and none of them should need a deploy to change.
    |
    */

    'withdrawals' => [

        /*
         * The member bears the provider's fee, as a separate Fee debit beside the
         * withdrawal. A member who withdraws four times is not paid for by one who
         * withdraws once. Share-out is still paid to the exact ngwee INTO the wallet;
         * the fee only arises when the member chooses to take it out.
         */
        'fee_bearer' => env('WALLET_WITHDRAWAL_FEE_BEARER', FeeBearer::Customer->value),

        /*
         * When a member may take money out. "always" is the committee's answer: a
         * wallet holds only uncommitted money, and it is the member's. "share_out"
         * would hold every balance until the cycle's payout.
         */
        'allowed_from' => env('WALLET_WITHDRAWALS_ALLOWED_FROM', 'always'),

        /* So a provider fee is never a large fraction of what is sent. */
        'min_ngwee' => (int) env('WALLET_WITHDRAWAL_MIN_NGWEE', 5_000),

        /*
         * What the fee is assumed to be while the transfer is in flight.
         *
         * The provider only tells us the real figure once the money has gone, and by
         * then the member could have spent the balance it has to come out of. So the
         * estimate is debited up front and squared up against the real fee when the
         * transfer confirms — a small Adjustment either way, both of them on the
         * statement. Set it a little high: over-reserving is refunded, under-reserving
         * is a wallet that cannot cover its own fee.
         */
        'fee_estimate_ngwee' => (int) env('WALLET_WITHDRAWAL_FEE_ESTIMATE_NGWEE', 1_000),

        /*
         * Cash out of a wallet is stricter than the fund's threshold rule: every
         * amount needs two signatures. A provider transfer leaves a record at the
         * provider; a banknote handed across the table leaves only this entry.
         */
        'cash_requires_second_signature' => (bool) env('WALLET_CASH_OUT_SECOND_SIGNATURE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Top-ups
    |--------------------------------------------------------------------------
    |
    | A top-up is always acceptable — there is no rule under which the group will
    | not take money into a member's own wallet — so the only floor is the one the
    | provider itself will not go below.
    |
    */

    'top_ups' => [
        'min_ngwee' => (int) env('WALLET_TOP_UP_MIN_NGWEE', 100),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rollover
    |--------------------------------------------------------------------------
    |
    | A non-zero balance at cycle end moves by a paired CarryForward entry: debit
    | the old wallet, credit the new. Never a silent copy — the balance must stay
    | derivable from entries in both cycles.
    |
    */

    'rollover' => [
        'carry_forward' => (bool) env('WALLET_CARRY_FORWARD', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Reconciliation
    |--------------------------------------------------------------------------
    |
    | Invariant 1: the sum of every wallet balance equals the provider balance plus
    | the cash tin, net of withdrawals in flight. A mismatch is an alarm, not a
    | report — it is the only check that catches a wallet credited with no money
    | behind it, which needs no ledger tampering at all.
    |
    */

    'reconciliation' => [
        /* Anything above this is an alarm. Zero means exact agreement is required. */
        'tolerance_ngwee' => (int) env('WALLET_RECONCILE_TOLERANCE_NGWEE', 0),
    ],

];
