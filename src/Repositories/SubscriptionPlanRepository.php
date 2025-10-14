<?php

namespace Lyre\Billing\Repositories;

use Lyre\Billing\Http\Resources\SubscriptionPlan as ResourcesSubscriptionPlan;
use Lyre\Billing\Models\Invoice;
use Lyre\Billing\Models\Subscription;
use Lyre\Repository;
use Lyre\Billing\Models\SubscriptionPlan;
use Lyre\Billing\Contracts\SubscriptionPlanRepositoryInterface;
// use App\Services\Paypal\Subscription as PaypalSubscription;
use Lyre\Exceptions\CommonException;

class SubscriptionPlanRepository extends Repository implements SubscriptionPlanRepositoryInterface
{
    protected $model;

    public function __construct(SubscriptionPlan $model)
    {
        parent::__construct($model);
    }

    public function subscribe(SubscriptionPlan $plan)
    {
        $subscription = Subscription::firstOrCreate([
            'user_id' => auth()->id(),
            'subscription_plan_id' => $plan->id,
            'status' => 'pending',
        ], [
            'start_date' => now(),
            'auto_renew' => true,
            'end_date' => now()->{$plan->billing_cycle == 'annually' ? 'addYear' : 'addMonth'}(),
        ]);

        if (!$subscription->wasRecentlyCreated) {
            throw CommonException::fromMessage('You are already subscribed to this plan.');
        }

        // TODO: Kigathi - July 2 2025 - This method assumes exams are the only subscriptable, and that product is an actual App\Models\Product entity
        $examCount = $plan->product->max_product_entities;

        // if ($examCount == 0) {
        //     $productIds = Exam::all()->pluck('id')->toArray();
        //     self::subscribeToProduct($subscription, $productIds);
        // } else {
        //     $product = request()->query('product');
        //     if ($product) {
        //         $parts = explode(',', $product);
        //         $productType = array_shift($parts);
        //         $productIds = Exam::whereIn('id', $parts)->pluck('id')->toArray();
        //         self::subscribeToProduct($subscription, $productIds, $productType);
        //     }
        // }

        $invoice = Invoice::create([
            'amount' => $plan->price,
            'subscription_id' => $subscription->id
        ]);

        // $links = PaypalSubscription::fromAspireSubscription($subscription, $invoice->invoice_number);

        // return ResourcesSubscriptionPlan::make($plan)->additional(['links' => $links]);
    }
}
