<?php

namespace Lyre\Billing\Services\Stripe;

use Illuminate\Support\Arr;
use Lyre\Billing\Models\PaymentMethod;

class Client
{
    public static function make(?string $stripeVersion = null): \Stripe\StripeClient
    {
        if (! class_exists(\Stripe\StripeClient::class)) {
            throw new \RuntimeException('stripe/stripe-php is required. Install it in lyre/billing.');
        }

        $paymentMethod = PaymentMethod::get('stripe');
        $secret = data_get($paymentMethod, 'details.STRIPE_SECRET') ?: config('services.stripe.secret');

        if (! $secret) {
            throw new \RuntimeException('Stripe secret is not configured.');
        }

        $config = ['api_key' => $secret];
        $resolvedVersion = $stripeVersion ?: static::apiVersion();

        if ($resolvedVersion) {
            $config['stripe_version'] = $resolvedVersion;
        }

        return new \Stripe\StripeClient($config);
    }

    public static function webhookSecret(): ?string
    {
        return static::webhookSecrets()[0] ?? null;
    }

    public static function webhookSecrets(): array
    {
        $paymentMethod = PaymentMethod::get('stripe');
        $configuredSecrets = config('billing.providers.stripe.webhook_secrets', []);

        $secrets = array_merge(
            static::normalizeSecrets(data_get($paymentMethod, 'details.STRIPE_WEBHOOK_SECRETS')),
            static::normalizeSecrets(data_get($paymentMethod, 'details.STRIPE_WEBHOOK_SECRET')),
            static::normalizeSecrets(data_get($paymentMethod, 'details.STRIPE_SNAPSHOT_WEBHOOK_SECRET')),
            static::normalizeSecrets(data_get($paymentMethod, 'details.STRIPE_THIN_WEBHOOK_SECRET')),
            static::normalizeSecrets($configuredSecrets),
            static::normalizeSecrets(config('billing.providers.stripe.snapshot_webhook_secret')),
            static::normalizeSecrets(config('billing.providers.stripe.thin_webhook_secret')),
            static::normalizeSecrets(config('services.stripe.webhook_secret'))
        );

        return array_values(array_unique(array_filter($secrets)));
    }

    public static function apiVersion(): ?string
    {
        $paymentMethod = PaymentMethod::get('stripe');

        return data_get($paymentMethod, 'details.STRIPE_API_VERSION')
            ?: config('billing.providers.stripe.api_version')
            ?: config('services.stripe.api_version');
    }

    public static function thinWebhookApiVersion(): ?string
    {
        $paymentMethod = PaymentMethod::get('stripe');

        return data_get($paymentMethod, 'details.STRIPE_THIN_WEBHOOK_API_VERSION')
            ?: config('billing.providers.stripe.thin_webhook_api_version')
            ?: static::apiVersion();
    }

    protected static function normalizeSecrets(mixed $candidate): array
    {
        if (is_array($candidate)) {
            return array_values(array_filter(array_map(
                static fn (mixed $secret): string => trim((string) $secret),
                Arr::flatten($candidate)
            )));
        }

        $value = trim((string) $candidate);
        if ($value === '') {
            return [];
        }

        if (! str_contains($value, ',')) {
            return [$value];
        }

        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }
}
