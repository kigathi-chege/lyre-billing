<?php

return [
    'models' => [
        'subscription' => \Lyre\Billing\Models\Subscription::class,
        'subscription_plan' => \Lyre\Billing\Models\SubscriptionPlan::class,
        'invoice' => \Lyre\Billing\Models\Invoice::class,
        'subscription_entitlement' => \Lyre\Billing\Models\SubscriptionEntitlement::class,
        'legacy_subscription_product' => env('BILLING_LEGACY_SUBSCRIPTION_PRODUCT_MODEL'),
    ],
    'providers' => [
        'mpesa' => [
            'enabled' => env('BILLING_MPESA_ENABLED', true),
            'callback_url' => env('BILLING_MPESA_CALLBACK_URL', env('APP_URL') . '/payments/mpesa/callback'),
        ],
        'paypal' => [
            'enabled' => env('BILLING_PAYPAL_ENABLED', true),
            'callback_url' => env('BILLING_PAYPAL_CALLBACK_URL', env('APP_URL') . '/payments/paypal/callback'),
            'return_url' => env('BILLING_PAYPAL_RETURN_URL', env('APP_URL') . '/payments/paypal/return'),
            'cancel_url' => env('BILLING_PAYPAL_CANCEL_URL', env('APP_URL') . '/payments/paypal/cancel'),
            'plan_subscription_service' => \Lyre\Billing\Services\Paypal\PlanSubscriptionService::class,
            'subscription_lifecycle_service' => \Lyre\Billing\Services\Paypal\SubscriptionLifecycleService::class,
            'webhook_handler' => \Lyre\Billing\Services\Paypal\WebhookEventHandler::class,
        ],
        'stripe' => [
            'enabled' => env('BILLING_STRIPE_ENABLED', true),
            'callback_url' => env('BILLING_STRIPE_CALLBACK_URL', env('APP_URL') . '/payments/stripe/callback'),
            'return_url' => env('BILLING_STRIPE_RETURN_URL', env('APP_URL') . '/payments/stripe/return'),
            'cancel_url' => env('BILLING_STRIPE_CANCEL_URL', env('APP_URL') . '/payments/stripe/cancel'),
            'plan_subscription_service' => \Lyre\Billing\Services\Stripe\PlanSubscriptionService::class,
            'subscription_lifecycle_service' => \Lyre\Billing\Services\Stripe\SubscriptionLifecycleService::class,
            'webhook_handler' => \Lyre\Billing\Services\Stripe\WebhookEventHandler::class,
        ],
        'paystack' => [
            'enabled' => env('BILLING_PAYSTACK_ENABLED', true),
            'callback_url' => env('BILLING_PAYSTACK_CALLBACK_URL', env('APP_URL') . '/payments/paystack/callback'),
            'return_url' => env('BILLING_PAYSTACK_RETURN_URL', env('APP_URL') . '/payments/paystack/return'),
        ],
    ],
    'subscriptions' => [
        'provider' => env('BILLING_SUBSCRIPTION_PROVIDER', 'paypal'),
    ],
    'entitlements' => [
        // Legacy-compat map for query payloads like ?product=exam,1,2
        'type_map' => [
            'exam' => env('BILLING_ENTITLEMENT_EXAM_MODEL'),
            'assessment' => env('BILLING_ENTITLEMENT_ASSESSMENT_MODEL'),
            'course' => env('BILLING_ENTITLEMENT_COURSE_MODEL'),
        ],
    ],
];
