<?php

/*
|--------------------------------------------------------------------------
| Billing: plans, limits and subscription dates
|--------------------------------------------------------------------------
| Master switch: while BILLING_ENABLED is false nobody is limited or blocked (current behaviour),
| so this can be deployed before the plans are announced. Prices are in cents.
|
| Limits: null = unlimited. Features: true/false.
| "plans" below only seed the `plans` table (php artisan billing:sync-plans [--force]).
*/
return [
    'enabled' => (bool) env('BILLING_ENABLED', false),

    'currency' => env('BILLING_CURRENCY', 'BRL'),

    // Free trial given on the FIRST paid subscription of a user (0 = none)
    'trial_days' => (int) env('BILLING_TRIAL_DAYS', 7),

    // The renewal invoice is issued this many days before the period ends
    'invoice_days_before' => (int) env('BILLING_INVOICE_DAYS_BEFORE', 5),

    // After the period ends without payment: full access continues for this many days (status past_due)
    'grace_days' => (int) env('BILLING_GRACE_DAYS', 3),

    // ...then the account is blocked (read-only, status suspended) for this many days before falling back to Free
    'suspension_days' => (int) env('BILLING_SUSPENSION_DAYS', 30),

    // A paid plan chosen without trial must be paid within this many days or the request expires
    'pending_days' => (int) env('BILLING_PENDING_DAYS', 3),

    'yearly_months_charged' => 10, // yearly = 10 monthly payments (2 months free)

    'plans' => [
        'free' => [
            'name' => 'Free',
            'sort' => 0,
            'price_cents' => 0,
            'features' => [
                'chat' => false,
                'ai_auto_reply' => false,
                'welcome_campaign' => false,
                'warmup' => false,
                'api_access' => false,
                'mcp_access' => false,
                'priority_support' => false,
            ],
            'limits' => [
                'instances' => 1,
                'contacts' => 100,
                'messages_per_month' => 100,
                'ai_replies_per_month' => 0,
            ],
        ],
        'starter' => [
            'name' => 'Starter',
            'sort' => 1,
            'price_cents' => 4900,
            'features' => [
                'chat' => true,
                'ai_auto_reply' => false,
                'welcome_campaign' => false,
                'warmup' => false,
                'api_access' => false,
                'mcp_access' => false,
                'priority_support' => false,
            ],
            'limits' => [
                'instances' => 2,
                'contacts' => 2000,
                'messages_per_month' => 2000,
                'ai_replies_per_month' => 0,
            ],
        ],
        'pro' => [
            'name' => 'Pro',
            'sort' => 2,
            'price_cents' => 12900,
            'features' => [
                'chat' => true,
                'ai_auto_reply' => true,
                'welcome_campaign' => true,
                'warmup' => true,
                'api_access' => true,
                'mcp_access' => false,
                'priority_support' => false,
            ],
            'limits' => [
                'instances' => 5,
                'contacts' => 20000,
                'messages_per_month' => 10000,
                'ai_replies_per_month' => 1000,
            ],
        ],
        'business' => [
            'name' => 'Business',
            'sort' => 3,
            'price_cents' => 29900,
            'features' => [
                'chat' => true,
                'ai_auto_reply' => true,
                'welcome_campaign' => true,
                'warmup' => true,
                'api_access' => true,
                'mcp_access' => true,
                'priority_support' => true,
            ],
            'limits' => [
                'instances' => 15,
                'contacts' => null,
                'messages_per_month' => 50000,
                'ai_replies_per_month' => 10000,
            ],
        ],
    ],
];
