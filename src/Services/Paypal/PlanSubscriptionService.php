<?php

namespace Lyre\Billing\Services\Paypal;

use Illuminate\Database\Eloquent\Model;
use Lyre\Billing\Models\Invoice;

class PlanSubscriptionService
{
    public function startCheckout(Model $plan, Model $subscription, Invoice $invoice): array
    {
        if (! PaypalModelBridge::getPlanId($plan)) {
            SubscriptionPlan::fromSubscriptionPlan($plan);
        }

        return Subscription::fromSubscription($subscription, $invoice->invoice_number) ?? [];
    }
}
