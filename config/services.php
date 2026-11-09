<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
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

    'onfon' => [
        'endpoint' => env('ONFON_SMS_ENDPOINT', 'https://api.onfonmedia.co.ke/v1/sms/SendBulkSMS'),
        'client_id' => env('ONFON_SMS_CLIENT_ID'),
        'api_key' => env('ONFON_SMS_API_KEY'),
        'access_key' => env('ONFON_SMS_ACCESS_KEY'),
        'sender_id' => env('ONFON_SMS_SENDER_ID', 'GOWNSEA'),
    ],

    'daraja' => [
        'enabled' => (bool) env('DARAJA_ENABLED', false),
        'environment' => env('DARAJA_ENVIRONMENT', 'sandbox'),
        'consumer_key' => env('DARAJA_CONSUMER_KEY'),
        'consumer_secret' => env('DARAJA_CONSUMER_SECRET'),
        'shortcode' => env('DARAJA_SHORTCODE'),
        'passkey' => env('DARAJA_PASSKEY'),
        'transaction_type' => env('DARAJA_TRANSACTION_TYPE', 'CustomerPayBillOnline'),
        'callback_token' => env('DARAJA_CALLBACK_TOKEN'),
    ],

    'etims' => [
        'enabled' => (bool) env('ETIMS_ENABLED', false),
        'provider' => env('ETIMS_PROVIDER', 'kra_vscu'),
        'vscu_endpoint' => env('ETIMS_VSCU_ENDPOINT'),
        'connect_timeout' => (int) env('ETIMS_CONNECT_TIMEOUT', 5),
        'timeout' => (int) env('ETIMS_TIMEOUT', 20),
    ],

    'gownsea_backup' => [
        'encryption_key' => env('GOWNSEA_BACKUP_ENCRYPTION_KEY'),
        'directory' => env('GOWNSEA_BACKUP_DIRECTORY') ?: storage_path('app/private/backups'),
        'private_directory' => storage_path('app/private'),
        'retention_days' => (int) env('GOWNSEA_BACKUP_RETENTION_DAYS', 30),
        'external_disk' => env('GOWNSEA_BACKUP_EXTERNAL_DISK'),
        'external_prefix' => env('GOWNSEA_BACKUP_EXTERNAL_PREFIX', 'gownsea-backups'),
    ],

];
