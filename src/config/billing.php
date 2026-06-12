<?php

return [
    'client_url' => env('BILLING_CLIENT_URL', env('CLIENT_URL', env('APP_CLIENT_URL', 'https://aspirecareerconsultants.com'))),

    'models' => [
        'subscription' => \Lyre\Billing\Models\Subscription::class,
        'subscription_plan' => \Lyre\Billing\Models\SubscriptionPlan::class,
        'invoice' => \Lyre\Billing\Models\Invoice::class,
        'subscription_entitlement' => \Lyre\Billing\Models\SubscriptionEntitlement::class,
    ],
    'providers' => [
        'mpesa' => [
            'enabled' => env('BILLING_MPESA_ENABLED', true),
            'callback_url' => env('BILLING_MPESA_CALLBACK_URL', env('APP_URL') . '/payments/mpesa/callback'),
        ],
        'paypal' => [
            'enabled' => env('BILLING_PAYPAL_ENABLED', true),
            'callback_url' => env('BILLING_PAYPAL_CALLBACK_URL', env('APP_URL') . '/api/billing/webhook?provider=paypal'),
            'return_url' => env('BILLING_PAYPAL_RETURN_URL', env('APP_URL') . '/api/billing/subscriptions/provider-return-redirect?provider=paypal'),
            'cancel_url' => env('BILLING_PAYPAL_CANCEL_URL', env('APP_URL') . '/api/billing/subscriptions/provider-cancel-redirect?provider=paypal'),
            'plan_subscription_service' => \Lyre\Billing\Services\Paypal\PlanSubscriptionService::class,
            'subscription_lifecycle_service' => \Lyre\Billing\Services\Paypal\SubscriptionLifecycleService::class,
            'webhook_handler' => \Lyre\Billing\Services\Paypal\WebhookEventHandler::class,
        ],
        'stripe' => [
            'enabled' => env('BILLING_STRIPE_ENABLED', true),
            'callback_url' => env('BILLING_STRIPE_CALLBACK_URL', env('APP_URL') . '/api/billing/webhook?provider=stripe'),
            'return_url' => env('BILLING_STRIPE_RETURN_URL', env('APP_URL') . '/api/billing/subscriptions/provider-return-redirect?provider=stripe'),
            'cancel_url' => env('BILLING_STRIPE_CANCEL_URL', env('APP_URL') . '/api/billing/subscriptions/provider-cancel-redirect?provider=stripe'),
            'webhook_secrets' => env('STRIPE_WEBHOOK_SECRETS'),
            'snapshot_webhook_secret' => env('STRIPE_SNAPSHOT_WEBHOOK_SECRET'),
            'thin_webhook_secret' => env('STRIPE_THIN_WEBHOOK_SECRET'),
            'api_version' => env('STRIPE_API_VERSION'),
            'thin_webhook_api_version' => env('STRIPE_THIN_WEBHOOK_API_VERSION'),
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
        'type_map' => [
            'exam' => env('BILLING_ENTITLEMENT_EXAM_MODEL'),
            'assessment' => env('BILLING_ENTITLEMENT_ASSESSMENT_MODEL'),
            'course' => env('BILLING_ENTITLEMENT_COURSE_MODEL'),
        ],
    ],
];
