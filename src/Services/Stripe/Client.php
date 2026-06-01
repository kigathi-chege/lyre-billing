<?php

namespace Lyre\Billing\Services\Stripe;

use Lyre\Billing\Models\PaymentMethod;

class Client
{
    public static function make(): \Stripe\StripeClient
    {
        if (! class_exists(\Stripe\StripeClient::class)) {
            throw new \RuntimeException('stripe/stripe-php is required. Install it in lyre/billing.');
        }

        $paymentMethod = PaymentMethod::get('stripe');
        $secret = data_get($paymentMethod, 'details.STRIPE_SECRET') ?: config('services.stripe.secret');

        if (! $secret) {
            throw new \RuntimeException('Stripe secret is not configured.');
        }

        return new \Stripe\StripeClient($secret);
    }

    public static function webhookSecret(): ?string
    {
        $paymentMethod = PaymentMethod::get('stripe');

        return data_get($paymentMethod, 'details.STRIPE_WEBHOOK_SECRET')
            ?: config('services.stripe.webhook_secret');
    }
}
