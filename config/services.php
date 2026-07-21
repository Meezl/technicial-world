<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'mpesa' => [
        'consumer_key' => env('MPESA_CONSUMER_KEY'),
        'consumer_secret' => env('MPESA_CONSUMER_SECRET'),
        'shortcode' => env('MPESA_SHORTCODE', '174379'),
        'passkey' => env('MPESA_PASSKEY'),
        'callback_url' => env('MPESA_CALLBACK_URL'),
        'environment' => env('MPESA_ENVIRONMENT', 'sandbox'),
    ],

    // Company bank details shown to clients on quotations, invoices, and
    // payment-request emails. Defaults are Technician World's live NCBA
    // account so the details render out-of-the-box; env vars override for
    // dev / staging environments where a test account is preferred.
    'bank' => [
        'name'           => env('BANK_NAME', 'NCBA'),
        'branch'         => env('BANK_BRANCH', 'Yaya Center'),
        'account_name'   => env('BANK_ACCOUNT_NAME', 'Technician World K Ltd'),
        'account_number' => env('BANK_ACCOUNT_NUMBER', '6064760016'),
        'swift_code'     => env('BANK_SWIFT_CODE', 'CBAFKENX'),
    ],

];
