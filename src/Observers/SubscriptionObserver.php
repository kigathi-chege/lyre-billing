<?php

namespace Lyre\Billing\Observers;

// use App\Services\Paypal\Subscription;
use Carbon\Carbon;
use Lyre\Observer;

class SubscriptionObserver extends Observer
{
    public function updated($model): void
    {
        if (!$model->paypal_id) {
            // Subscription::fromAspireSubscription($model);
            return;
        }

        $changes = $model->getChanges();

        // if (isset($changes['start_time'])) {
        //     Subscription::updateStartTime($model->paypal_id, Carbon::parse($model->start_date)->copy()->setTimezone('UTC')->format('Y-m-d\TH:i:s\Z'));
        // }

        // if (isset($changes['status'])) {
        //     $response = Subscription::getSubscriptionDetails($model->paypal_id);
        //     if ($response['status'] == 'ACTIVE' && $model->status == 'active') {
        //         return;
        //     }

        //     Subscription::updateSubscriptionStatus($model->paypal_id, match ($model->status) {
        //         'active' => 'activate',
        //         'canceled' => 'cancel',
        //         'paused' => 'suspend',
        //     }, "Status changed from {$model->getOriginal('status')} to {$model->status}");
        // }

        parent::updated($model);
    }
}
