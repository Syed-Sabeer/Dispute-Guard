<?php

declare(strict_types=1);

return [
    'name' => env('CHARGEGUARD_NAME', 'ChargeGuard'),
    'test_mode' => env('CHARGEGUARD_TEST_MODE', true),
    'demo_mode' => env('CHARGEGUARD_DEMO_MODE', false),
    'retention_days' => (int) env('CHARGEGUARD_RETENTION_DAYS', 90),
    'billing_enforced' => env('BILLING_ENFORCED', true),
    'billing' => [
        'starter' => env('SHOPIFY_PLAN_STARTER', 'starter'),
        'growth' => env('SHOPIFY_PLAN_GROWTH', 'growth'),
        'pro' => env('SHOPIFY_PLAN_PRO', 'pro'),
    ],
    'billing_items' => [
        'starter' => env('SHOPIFY_ITEM_STARTER', 'starter'),
        'growth' => env('SHOPIFY_ITEM_GROWTH', 'growth'),
        'pro' => env('SHOPIFY_ITEM_PRO', 'pro'),
    ],
];
