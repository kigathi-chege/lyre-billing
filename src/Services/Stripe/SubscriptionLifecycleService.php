<?php

namespace Lyre\Billing\Services\Stripe;

use Illuminate\Database\Eloquent\Model;
use Lyre\Billing\Events\SubscriptionActivated;
use Lyre\Billing\Events\SubscriptionExpired;
use Lyre\Billing\Events\SubscriptionPaymentFailed;
use Lyre\Billing\Events\SubscriptionRenewalDue;
use Lyre\Billing\Events\SubscriptionSuspended;
use Lyre\Billing\Support\BillingSupport;
use Lyre\Jobs\SendEmails;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SubscriptionLifecycleService
{
    public function revokeRenewal(Model $subscription): Model
    {
        return $this->setRenewal($subscription, false);
    }

    public function restoreRenewal(Model $subscription): Model
    {
        return $this->setRenewal($subscription, true);
    }

    public function approveByProviderId(string $providerId, mixed $invoice = null): mixed
    {
        $subscription = StripeModelBridge::findByStripeSubscriptionId($providerId);
        if (! $subscription) {
            throw new \RuntimeException("Subscription for Stripe ID [{$providerId}] not found.");
        }

        $subscription->update(['status' => 'active']);

        SendEmails::dispatch(
            email: BillingSupport::subscriptionEmail($subscription),
            subject: 'Subscription Activated',
            view: 'email.subscriptions.activated',
            data: [
                'name' => BillingSupport::subscriptionName($subscription),
                'buttonText' => 'Log In',
                'buttonLink' => rtrim((string) config('app.url'), '/') . '/login',
            ]
        );

        event(new SubscriptionActivated($subscription, $invoice));

        return $subscription;
    }

    public function suspendByProviderId(string $providerId): mixed
    {
        $subscription = StripeModelBridge::findByStripeSubscriptionId($providerId);
        if (! $subscription) {
            throw new \RuntimeException("Subscription for Stripe ID [{$providerId}] not found.");
        }

        $subscription->update(['status' => 'paused']);

        SendEmails::dispatch(
            email: BillingSupport::subscriptionEmail($subscription),
            subject: 'Subscription Suspended',
            view: 'email.subscriptions.suspended',
            data: [
                'name' => BillingSupport::subscriptionName($subscription),
                'buttonText' => 'Manage Billing',
                'buttonLink' => rtrim((string) config('app.url'), '/'),
            ]
        );

        event(new SubscriptionSuspended($subscription));

        return $subscription;
    }

    public function paymentFailedByProviderId(string $providerId, mixed $invoice = null): mixed
    {
        $subscription = StripeModelBridge::findByStripeSubscriptionId($providerId);
        if (! $subscription) {
            throw new \RuntimeException("Subscription for Stripe ID [{$providerId}] not found.");
        }

        SendEmails::dispatch(
            email: BillingSupport::subscriptionEmail($subscription),
            subject: 'Subscription Payment Failed',
            view: 'email.subscriptions.suspended',
            data: [
                'name' => BillingSupport::subscriptionName($subscription),
                'buttonText' => 'Update Payment Method',
                'buttonLink' => rtrim((string) config('app.url'), '/'),
            ]
        );

        event(new SubscriptionPaymentFailed($subscription, $invoice));

        return $subscription;
    }

    public function expire(mixed $subscription): mixed
    {
        $subscription->status = 'expired';
        $subscription->save();

        SendEmails::dispatch(
            email: BillingSupport::subscriptionEmail($subscription),
            subject: 'Subscription Expiry',
            view: 'email.subscriptions.expired',
            data: [
                'name' => BillingSupport::subscriptionName($subscription),
                'buttonText' => 'Renew Now',
                'buttonLink' => rtrim((string) config('app.url'), '/'),
            ]
        );

        event(new SubscriptionExpired($subscription));

        return $subscription;
    }

    public function markRenewalDue(mixed $subscription): mixed
    {
        SendEmails::dispatch(
            email: BillingSupport::subscriptionEmail($subscription),
            subject: 'Subscription Expiry',
            view: 'email.subscriptions.expiring',
            data: [
                'name' => BillingSupport::subscriptionName($subscription),
                'buttonText' => 'Renew Now',
                'buttonLink' => rtrim((string) config('app.url'), '/'),
            ]
        );

        event(new SubscriptionRenewalDue($subscription));

        return $subscription;
    }

    protected function setRenewal(Model $subscription, bool $enabled): Model
    {
        $providerId = StripeModelBridge::getSubscriptionId($subscription);
        if (! $providerId) {
            throw new HttpException(422, 'This subscription is missing its Stripe subscription reference.');
        }

        try {
            $providerSubscription = $this->setCancelAtPeriodEnd($providerId, ! $enabled);
        } catch (\Throwable $exception) {
            report($exception);
            throw new HttpException(502, 'Stripe could not update this subscription. Please try again.', $exception);
        }

        $cancelAtPeriodEnd = data_get($providerSubscription, 'cancel_at_period_end');
        if ($cancelAtPeriodEnd === null || (bool) $cancelAtPeriodEnd !== ! $enabled) {
            throw new HttpException(502, 'Stripe returned an unexpected renewal state. Please try again.');
        }

        $subscription->auto_renew = ! (bool) $cancelAtPeriodEnd;
        if (data_get($providerSubscription, 'current_period_end')) {
            $subscription->end_date = now()->setTimestamp((int) data_get($providerSubscription, 'current_period_end'));
        }
        $subscription->save();

        return $subscription->fresh();
    }

    protected function setCancelAtPeriodEnd(string $providerId, bool $cancelAtPeriodEnd): mixed
    {
        return Client::make()->subscriptions->update($providerId, [
            'cancel_at_period_end' => $cancelAtPeriodEnd,
        ]);
    }
}
