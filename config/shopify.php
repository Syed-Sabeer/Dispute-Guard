<?php

declare(strict_types=1);

return [
    'api_key' => env('SHOPIFY_API_KEY'),
    'api_secret' => env('SHOPIFY_API_SECRET'),
    'api_version' => env('SHOPIFY_API_VERSION', '2026-07'),
    'app_handle' => env('SHOPIFY_APP_HANDLE'),
    'billing_mode' => env('SHOPIFY_BILLING_MODE', 'app_pricing'),
    'scopes' => ['read_orders', 'read_shopify_payments_disputes'],
    'partner_id' => env('SHOPIFY_PARTNER_ID'),
    'partner_token' => env('SHOPIFY_PARTNER_TOKEN'),
    'app_id' => env('SHOPIFY_APP_ID'),
    'partner_api_version' => env('SHOPIFY_PARTNER_API_VERSION', '2026-07'),
];
