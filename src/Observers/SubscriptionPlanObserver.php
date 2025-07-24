<?php

namespace Lyre\Billing\Observers;

// use App\Services\Paypal\SubscriptionPlan;
use Lyre\Observer;

class SubscriptionPlanObserver extends Observer
{
    public function updated($model): void
    {
        if (!$model->paypal_plan_id) {
            // SubscriptionPlan::fromAspireSubscriptionPlan($model);
            return;
        }

        $changes = $model->getChanges();

        // if (isset($changes['name'])) {
        //     SubscriptionPlan::updatePlanName($model->paypal_plan_id, $model->name);
        // }

        // if (isset($changes['description'])) {
        //     SubscriptionPlan::updatePlanDescription($model->paypal_plan_id, $model->description);
        // }

        // if (isset($changes['status'])) {
        //     SubscriptionPlan::activateDeactivatePlan($model->paypal_plan_id, $model->status === 'active');
        // }

        // if (isset($changes['price']) || isset($changes['billing_cycle'])) {
        //     $pricingSchemes = [
        //         [
        //             'billing_cycle_sequence' => $model->trial_days > 0 ? 2 : 1,
        //             'pricing_scheme' => [
        //                 'fixed_price' => [
        //                     'value' => $model->price,
        //                     'currency_code' => 'USD'
        //                 ]
        //             ]
        //         ]
        //     ];

        //     SubscriptionPlan::updatePlanPricing($model->paypal_plan_id, $pricingSchemes);
        // }

        parent::updated($model);
    }
}
