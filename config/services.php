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
'web_push' => [
    'subject' => env('VAPID_SUBJECT'),
    'public_key' => env('VAPID_PUBLIC_KEY'),
    'private_key' => env('VAPID_PRIVATE_KEY'),
],

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

    'whatsapp' => [
        'factory_url' => env('WHATSAPP_FACTORY_URL', 'http://127.0.0.1:3081'),
        'bot_token' => env('BOT_TOKEN'),
        'worker_url' => rtrim((string) env('WHATSAPP_WORKER_URL', 'http://127.0.0.1:3080'), '/'),
    ],

    'storage_sync' => [
        'host' => env('STORAGE_SYNC_HOST'),
        'user' => env('STORAGE_SYNC_USER', 'root'),
        'port' => (int) env('STORAGE_SYNC_PORT', 22),
        'path' => env('STORAGE_SYNC_PATH'),
    ],


    // SMS to customers ("your request is under review" for every new request) through ZADX
    // (smsportal.zadx.net → Apps → Moto Gate). Until SMS_ENABLED and both keys are set, nothing is
    // sent and each message is logged as skipped in sms_logs.
    'sms' => [
        'enabled' => (bool) env('SMS_ENABLED', false),
        'url' => env('SMS_API_URL', 'https://smsapi.zadx.net/api/v1/sms/send'),
        'key' => env('SMS_API_KEY'),
        'secret' => env('SMS_API_SECRET'),
        'sender' => env('SMS_SENDER_ID', 'Moto Gate'),
        'new_request_message' => 'عميلنا العزيز، شكرًا لاختيارك موتوجيت. طلبك #:id قيد المراجعة، وسنتواصل معك قريبًا.',
    ],
];
