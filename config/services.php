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

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'vnpay' => [
        'payment_url' => env('VNPAY_PAYMENT_URL', 'https://sandbox.vnpayment.vn/paymentv2/vpcpay.html'),
        'terminal_code' => env('VNPAY_TERMINAL_CODE'),
        'hash_secret' => env('VNPAY_HASH_SECRET'),
        'return_url' => env('VNPAY_RETURN_URL'),
        'version' => env('VNPAY_VERSION', '2.1.0'),
        'timezone' => env('VNPAY_TIMEZONE', 'Asia/Ho_Chi_Minh'),
        'refund_url' => env('VNPAY_REFUND_URL', 'https://sandbox.vnpayment.vn/merchant_webapi/api/transaction'),
        'refund_create_by' => env('VNPAY_REFUND_CREATE_BY'),
        'refund_ip_address' => env('VNPAY_REFUND_IP_ADDRESS'),
        'refund_connect_timeout' => (int) env('VNPAY_REFUND_CONNECT_TIMEOUT', 5),
        'refund_timeout' => (int) env('VNPAY_REFUND_TIMEOUT', 15),
        'refund_submission_stale_seconds' => (int) env('VNPAY_REFUND_SUBMISSION_STALE_SECONDS', 120),
    ],

];
