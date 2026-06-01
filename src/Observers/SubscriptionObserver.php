<?php

namespace Lyre\Billing\Observers;

use Lyre\Billing\Support\BillingSupport;
use Lyre\Observer;

class SubscriptionObserver extends Observer
{
    public function updated($model): void
    {
        $provider = (string) config('billing.subscriptions.provider', 'paypal');
        $providerSubscriptionId = BillingSupport::getProviderValue($model, $provider, 'subscription_id');

        if (! $providerSubscriptionId) {
            return;
        }

        parent::updated($model);
    }
}
