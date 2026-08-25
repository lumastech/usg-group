<?php

return [

    /*
    |--------------------------------------------------------------------------
    | System Administrator
    |--------------------------------------------------------------------------
    |
    | The account seeded by AdminSeeder. Leaving the password unset causes a
    | random one to be generated and printed the first time the seeder runs.
    |
    */

    'admin' => [
        'name' => env('ADMIN_NAME', 'System Administrator'),
        'email' => env('ADMIN_EMAIL', 'admin@admin.com'),
        'password' => env('ADMIN_PASSWORD') ?: null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Open registration
    |--------------------------------------------------------------------------
    |
    | Off, and it must stay off anywhere real money is held. The group admits
    | members by resolution and the committee records them; /register exists for
    | the committee's own logins and attaches no membership.
    |
    | Turned on, a sign-up also registers the person into the current cycle
    | through MembershipRegistrar, so a tester can walk the whole cycle from a
    | fresh account. It buys no exemption: registration still closes after the
    | month the cycle says it does, and the joining fee tier still applies. Use
    | it with `unity:open-for-testing`, which is what moves that window.
    |
    */

    'open_registration' => (bool) env('UNITY_OPEN_REGISTRATION', false),

];
