<?php

return [
    'pricing_models' => [
        'free',
        'fixed',
        'usage_based'
    ],

    'billing_cycles' => [
        'per_minute',
        'per_hour',
        'per_day',
        'per_week',
        'monthly',
        'quarterly',
        'semi_annually',
        'annually'
    ],

    'subscription_statuses' => [
        'pending',
        'active',
        'paused',
        'canceled',
        'expired'
    ],

    'subscription_reminder_types' => [
        'renewal',
        'payment_due',
        'trial_end'
    ],

    'subscription_reminder_statuses' => [
        'pending',
        'sent'
    ],

    'providers' => [
        'mpesa',
        'paystack',
        'stripe',
        'paypal',
        'bank_transfer'
    ],

    'invoice_statuses' => [
        'paid',
        'pending',
        'failed'
    ],

    'webhook' => env("LYRE_BILLING_WEBHOOK"),
];
