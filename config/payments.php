<?php

use App\Domain\Payments\NullPaymentGateway;

return [

    /*
    |--------------------------------------------------------------------------
    | Gateway
    |--------------------------------------------------------------------------
    |
    | Which implementation of App\Domain\Payments\PaymentGateway the application
    | talks to. The default calls nothing and writes what it would have sent to the
    | log, exactly as the SMS seam does, so every screen, job and ledger path is
    | exercisable before the group holds a Lenco account. Setting PAYMENT_GATEWAY to
    | "lenco" is the only change needed to start moving real money.
    |
    */

    'default' => env('PAYMENT_GATEWAY', 'null'),

    'gateways' => [

        'null' => [
            'driver' => NullPaymentGateway::class,
            'log_channel' => env('PAYMENT_LOG_CHANNEL'),
        ],

        'lenco' => [
            'base_url' => env('LENCO_BASE_URL', 'https://api.lenco.co/access/v2'),

            /*
             * Moves money out of the group's account. Server environment only — it is
             * never shared to Inertia, never logged, and rotated through Lenco support
             * if it is ever exposed.
             */
            'api_token' => env('LENCO_API_TOKEN'),

            /* Safe to send to the browser: the hosted widget needs it. */
            'public_key' => env('LENCO_PUBLIC_KEY'),

            /* The 36-character account uuid every transfer debits. */
            'account_id' => env('LENCO_ACCOUNT_ID'),

            'widget_url' => env('LENCO_WIDGET_URL', 'https://pay.lenco.co/js/v1/inline.js'),

            'country' => env('LENCO_COUNTRY', 'zm'),
            'currency' => env('LENCO_CURRENCY', 'ZMW'),

            'timeout' => (int) env('LENCO_TIMEOUT', 30),
            'retry_times' => (int) env('LENCO_RETRY_TIMES', 2),
            'retry_sleep_ms' => (int) env('LENCO_RETRY_SLEEP_MS', 500),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | References
    |--------------------------------------------------------------------------
    |
    | Every payment carries a reference we generate, and the provider rejects a
    | duplicate. The prefix must differ between sandbox and live so a reference
    | issued while testing can never collide with a real one.
    |
    */

    'reference_prefix' => env('PAYMENT_REFERENCE_PREFIX', 'usg'),

    /*
    |--------------------------------------------------------------------------
    | Collections
    |--------------------------------------------------------------------------
    |
    | Money in. The member bears the fee: a K500 contribution has to reach the
    | savings ledger as exactly K500 or the ledger and the bank disagree forever,
    | and the K500 increment rule leaves no room for K487.50.
    |
    | Mobile money is authorised on the handset, so nothing is known at request
    | time. The poller closes the gap the webhook leaves when it does not arrive.
    |
    */

    'collections' => [
        'bearer' => env('LENCO_COLLECTION_BEARER', 'customer'),

        'min_ngwee' => (int) env('PAYMENT_COLLECTION_MIN_NGWEE', 100),

        'poll' => [
            'every_minutes' => (int) env('PAYMENT_COLLECTION_POLL_MINUTES', 5),
            'give_up_after_minutes' => (int) env('PAYMENT_COLLECTION_GIVE_UP_MINUTES', 60),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Transfers
    |--------------------------------------------------------------------------
    |
    | Money out. The fee here is unavoidably the group's and is never netted off
    | what a member is owed — share-out is paid to the exact ngwee.
    |
    | A transfer whose outcome we never learn is escalated rather than abandoned:
    | money may have left the account and somebody has to go and look.
    |
    */

    'transfers' => [
        'require_verified_destination' => (bool) env('PAYMENT_REQUIRE_VERIFIED_DESTINATION', true),

        /* Kept back from the balance so a batch never drains the account to zero. */
        'balance_headroom_ngwee' => (int) env('PAYMENT_BALANCE_HEADROOM_NGWEE', 0),

        /*
         * A destination added or changed inside this window cannot be paid to without
         * a second committee signature. This is what defeats "take over the account,
         * change the number, wait for share-out".
         */
        'destination_cooling_off_hours' => (int) env('PAYMENT_DESTINATION_COOLING_OFF_HOURS', 48),

        'poll' => [
            'every_minutes' => (int) env('PAYMENT_TRANSFER_POLL_MINUTES', 15),
            'give_up_after_hours' => (int) env('PAYMENT_TRANSFER_GIVE_UP_HOURS', 24),
        ],
    ],

];
