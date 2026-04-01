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

    'paymongo' => [
        'secret_key' => env('PAYMONGO_SECRET_KEY'),
        'webhook_secret' => env('PAYMONGO_WEBHOOK_SECRET'),
        'api_base_url' => env('PAYMONGO_API_URL', 'https://api.paymongo.com'),
    ],

    'pusher' => [
        'app_id' => env('PUSHER_APP_ID'),
        'key' => env('PUSHER_APP_KEY'),
        'secret' => env('PUSHER_APP_SECRET'),
        'cluster' => env('PUSHER_APP_CLUSTER'),
        'use_tls' => env('PUSHER_APP_TLS', true),
    ],

    'vision_embedding' => [
        'url' => (function (): ?string {
            $env = (string) env('APP_ENV', 'production');
            if (in_array($env, ['local', 'development', 'dev'], true)) {
                return env('VISION_EMBEDDING_URL_LOCAL', env('VISION_EMBEDDING_URL'));
            }
            return env('VISION_EMBEDDING_URL');
        })(),
    ],

    'xde' => [
        'base_url' => env('XDE_BASE_URL'),
        'book_path' => env('XDE_BOOK_PATH', '/v2/pickup'),
        'track_path' => env('XDE_TRACK_PATH', '/status/{tracking_number}'),
        'cancel_path' => env('XDE_CANCEL_PATH', '/cancel/'),
        'waybill_path' => env('XDE_WAYBILL_PATH', '/generate-waybill-a6/{tracking_number}'),
        'epod_path' => env('XDE_EPOD_PATH', '/epod.php?tracking_number={tracking_number}'),
        'api_key' => env('XDE_API_KEY'),
        'token' => env('XDE_TOKEN'),
        'timeout' => (int) env('XDE_TIMEOUT', 20),
        'default_weight' => (float) env('XDE_DEFAULT_WEIGHT', 1),
        'default_length' => (float) env('XDE_DEFAULT_LENGTH', 10),
        'default_width' => (float) env('XDE_DEFAULT_WIDTH', 10),
        'default_height' => (float) env('XDE_DEFAULT_HEIGHT', 10),
        'default_volume' => (float) env('XDE_DEFAULT_VOLUME', 1),
        'package_size' => env('XDE_PACKAGE_SIZE', 'Bulky'),
        'package_type' => env('XDE_PACKAGE_TYPE', 'Sales_order'),
        'delivery_type' => env('XDE_DELIVERY_TYPE', 'Standard'),
        'shipping_type' => env('XDE_SHIPPING_TYPE', 'Local'),
        'journey_type' => env('XDE_JOURNEY_TYPE', 'Last Mile'),
        'transport_mode' => env('XDE_TRANSPORT_MODE', 'land'),
        'port_code' => env('XDE_PORT_CODE', 'MAIN'),
        'shipment_provider' => env('XDE_SHIPMENT_PROVIDER', 'Ximex Delivery Express'),
        'merchant_name' => env('XDE_MERCHANT_NAME', env('JNT_SENDER_NAME', 'AF Home Warehouse')),
        'merchant_address' => env('XDE_MERCHANT_ADDRESS', env('JNT_SENDER_ADDRESS')),
        'merchant_mobile' => env('XDE_MERCHANT_MOBILE', env('JNT_SENDER_MOBILE', env('JNT_SENDER_PHONE'))),
        'merchant_email' => env('XDE_MERCHANT_EMAIL', env('JNT_SENDER_EMAIL')),
        'merchant_province' => env('XDE_MERCHANT_PROVINCE', env('JNT_SENDER_PROVINCE')),
        'merchant_city' => env('XDE_MERCHANT_CITY', env('JNT_SENDER_CITY')),
        'merchant_barangay' => env('XDE_MERCHANT_BARANGAY'),
    ],

    'jnt' => [
        'base_url' => env('JNT_BASE_URL', env('JT_API_BASE_URL')),
        'book_path' => env('JNT_BOOK_PATH', '/webopenplatformapi/api/order/addOrder'),
        'track_path' => env('JNT_TRACK_PATH', '/webopenplatformapi/api/logistics/trace/query'),
        'customer_code' => env('JNT_CUSTOMER_CODE', env('JT_CUSTOMER_CODE')),
        'api_account' => env('JNT_API_ACCOUNT', env('JT_API_ACCOUNT')),
        'password' => env('JNT_PASSWORD', env('JT_API_PASSWORD')),
        'private_key' => env('JNT_PRIVATE_KEY', env('JT_PRIVATE_KEY')),
        'header_digest_override' => env('JNT_HEADER_DIGEST_OVERRIDE'),
        'is_sandbox' => filter_var(env('JNT_IS_SANDBOX', env('JT_IS_SANDBOX', true)), FILTER_VALIDATE_BOOL),
        'password_suffix' => env('JNT_PASSWORD_SUFFIX', 'jadata236t2'),
        'network' => env('JNT_NETWORK', ''),
        'service_type' => env('JNT_SERVICE_TYPE', '02'),
        'country_code' => env('JNT_COUNTRY_CODE', 'PHL'),
        'order_type' => env('JNT_ORDER_TYPE', '1'),
        'express_type' => env('JNT_EXPRESS_TYPE', 'standard'),
        'delivery_type' => env('JNT_DELIVERY_TYPE', '03'),
        'goods_type' => env('JNT_GOODS_TYPE', 'bm000001'),
        'price_currency' => env('JNT_PRICE_CURRENCY', 'PHP'),
        'operate_type' => (int) env('JNT_OPERATE_TYPE', 1),
        'default_weight' => (float) env('JNT_DEFAULT_WEIGHT', 1),
        'default_length' => (float) env('JNT_DEFAULT_LENGTH', 10),
        'default_width' => (float) env('JNT_DEFAULT_WIDTH', 10),
        'default_height' => (float) env('JNT_DEFAULT_HEIGHT', 10),
        'default_volume' => (float) env('JNT_DEFAULT_VOLUME', 1000),
        'offer_fee' => (float) env('JNT_OFFER_FEE', 0),
        'sender_name' => env('JNT_SENDER_NAME', 'AF Home Warehouse'),
        'sender_company' => env('JNT_SENDER_COMPANY', 'AF Home'),
        'sender_phone' => env('JNT_SENDER_PHONE'),
        'sender_mobile' => env('JNT_SENDER_MOBILE'),
        'sender_email' => env('JNT_SENDER_EMAIL'),
        'sender_post_code' => env('JNT_SENDER_POST_CODE'),
        'sender_province' => env('JNT_SENDER_PROVINCE'),
        'sender_city' => env('JNT_SENDER_CITY'),
        'sender_address' => env('JNT_SENDER_ADDRESS'),
        'timeout' => (int) env('JNT_TIMEOUT', 20),
    ],

];
