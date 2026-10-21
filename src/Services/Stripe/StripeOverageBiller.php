<?php

namespace Lyre\Billing\Services\Stripe;

use Illuminate\Database\Eloquent\Model;
use Lyre\Billing\Contracts\OverageBiller;

/**
 * Adds overage as a pending invoice item on the Stripe subscription, so it is collected with
 * the next renewal invoice; no metered price needs to exist in Stripe.
 */
class StripeOverageBiller implements OverageBiller
{
    public function supports(Model $subscription): bool
    {
        return (bool) data_get($subscription->metadata, 'providers.stripe.customer_id')
            && (bool) data_get($subscription->metadata, 'providers.stripe.subscription_id');
    }

    public function charge(Model $subscription, float $amount, string $currency, string $description, string $idempotencyKey): string
    {
        $invoiceItem = Client::make()->invoiceItems->create([
            'customer' => data_get($subscription->metadata, 'providers.stripe.customer_id'),
            'subscription' => data_get($subscription->metadata, 'providers.stripe.subscription_id'),
            'amount' => (int) round($amount * 100),
            'currency' => strtolower($currency),
            'description' => $description,
            'metadata' => ['subscription_id' => (string) $subscription->getKey()],
        ], ['idempotency_key' => $idempotencyKey]);

        return (string) $invoiceItem->id;
    }
}
