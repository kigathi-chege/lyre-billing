<?php

namespace Lyre\Billing\Observers;

use Lyre\Billing\Support\BillingSupport;
use Lyre\Observer;

class SubscriptionPlanObserver extends Observer
{
    public function updated($model): void
    {
        $provider = (string) config('billing.subscriptions.provider', 'paypal');
        $providerPlanId = BillingSupport::getProviderValue($model, $provider, 'plan_id');

        if (! $providerPlanId) {
            return;
        }

        parent::updated($model);
    }
}
