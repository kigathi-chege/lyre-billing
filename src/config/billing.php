<?php

return [
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
        ],
        'stripe' => [
            'enabled' => env('BILLING_STRIPE_ENABLED', true),
            'callback_url' => env('BILLING_STRIPE_CALLBACK_URL', env('APP_URL') . '/payments/stripe/callback'),
            'return_url' => env('BILLING_STRIPE_RETURN_URL', env('APP_URL') . '/payments/stripe/return'),
        ],
        'paystack' => [
            'enabled' => env('BILLING_PAYSTACK_ENABLED', true),
            'callback_url' => env('BILLING_PAYSTACK_CALLBACK_URL', env('APP_URL') . '/payments/paystack/callback'),
            'return_url' => env('BILLING_PAYSTACK_RETURN_URL', env('APP_URL') . '/payments/paystack/return'),
        ],
    ],
];
