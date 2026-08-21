<?php

use App\Domain\Notifications\Sms\LogSmsGateway;

return [

    /*
    |--------------------------------------------------------------------------
    | SMS
    |--------------------------------------------------------------------------
    |
    | The group reaches the portal by phone, so every scheduled notification is
    | written to send over SMS as well as email. No real gateway is wired up yet:
    | the default implementation writes the message it would have sent to the log,
    | which is enough to review copy and to assert on in tests. Swapping in a real
    | provider — Africa's Talking is the likely one — is a change of this binding
    | and nothing else, because App\Domain\Notifications\Sms\SmsGateway is the only
    | thing the channel knows about.
    |
    */

    'sms' => [
        'gateway' => env('SMS_GATEWAY', LogSmsGateway::class),

        'from' => env('SMS_FROM', 'UnitySavings'),

        /*
         * A GSM-7 message is 160 characters; anything longer is billed as several.
         * The channel truncates rather than silently sending a three-part message.
         */
        'max_length' => (int) env('SMS_MAX_LENGTH', 320),

        'log_channel' => env('SMS_LOG_CHANNEL', null),
    ],

    /*
    |--------------------------------------------------------------------------
    | Statement packs
    |--------------------------------------------------------------------------
    |
    | Where the pack built when a trading session is concluded is written. It must
    | match the disk the treasurer's own "build pack" button uses, or the mail-out
    | attaches files from a different build than the one on the reports hub.
    |
    */

    'statement_pack' => [
        'disk' => env('STATEMENT_PACK_DISK', 'local'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Backups
    |--------------------------------------------------------------------------
    |
    | The nightly dump written by `unity:backup-database`. See docs/STORAGE.md.
    |
    */

    'backups' => [
        'disk' => env('BACKUP_DISK', 'local'),
        'directory' => env('BACKUP_DIRECTORY', 'backups'),
        'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 30),
    ],

];
