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

];
