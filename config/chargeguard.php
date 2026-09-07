<?php

declare(strict_types=1);

return [
    'name' => env('CHARGEGUARD_NAME') && env('CHARGEGUARD_NAME') !== 'ChargeGuard' ? env('CHARGEGUARD_NAME') : 'Dispute Guard',
    'test_tools' => env('DISPUTEGUARD_ENABLE_TEST_TOOLS'),
    'billing_enabled' => env('BILLING_ENABLED', true),
    'prelaunch' => env('DISPUTEGUARD_PRELAUNCH', false),
    'prelaunch_shops' => array_values(array_filter(array_map('trim', explode(',', env('DISPUTEGUARD_PRELAUNCH_SHOPS', ''))))),
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
