<?php

namespace Lyre\Billing\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Charges metered overage to a subscription's provider (e.g. as an invoice item on the next
 * invoice). Providers without an implementation fall back to the upsell behaviour.
 */
interface OverageBiller
{
    public function supports(Model $subscription): bool;

    /**
     * Charge the amount and return the provider's reference for the charge.
     */
    public function charge(Model $subscription, float $amount, string $currency, string $description, string $idempotencyKey): string;
}
