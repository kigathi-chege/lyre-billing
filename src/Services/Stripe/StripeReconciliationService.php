<?php

namespace Lyre\Billing\Services\Stripe;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Lyre\Billing\Services\SubscriptionLifecycleService;

/**
 * Polls Stripe for the true status of a local subscription and corrects the local
 * record to match — the safety net for missed/failed webhooks. Reuses the same status
 * mapping (WebhookEventHandler::mapStripeStatus) and terminal setters
 * (SubscriptionLifecycleService) the webhook path uses, so a recovered subscription is
 * activated exactly as a webhook would (status + activation email + SubscriptionActivated).
 */
class StripeReconciliationService
{
    /**
     * Reconcile a single local subscription against Stripe.
     *
     * @param  array{dry_run?: bool}  $opts
     * @return array{subscription_id: mixed, stripe_id: ?string, stripe_status: ?string, from: string, to: ?string, action: string, changed: bool, dry_run: bool, error: ?string}
     */
    public function reconcile(Model $subscription, array $opts = []): array
    {
        $dryRun = (bool) ($opts['dry_run'] ?? false);
        $fromStatus = (string) $subscription->status;

        $outcome = [
            'subscription_id' => $subscription->getKey(),
            'stripe_id' => null,
            'stripe_status' => null,
            'from' => $fromStatus,
            'to' => null,
            'action' => 'none',
            'changed' => false,
            'dry_run' => $dryRun,
            'error' => null,
        ];

        try {
            $live = $this->fetchLiveSubscription($subscription);
        } catch (\Throwable $exception) {
            Log::warning('billing.stripe_reconcile.fetch_failed', [
                'subscription_id' => $subscription->getKey(),
                'message' => $exception->getMessage(),
            ]);
            $outcome['error'] = $exception->getMessage();

            return $outcome;
        }

        if (! $live) {
            $outcome['action'] = 'no_stripe_reference';

            return $outcome;
        }

        $stripeId = (string) data_get($live, 'id', '');
        $stripeStatus = (string) data_get($live, 'status', '');
        $outcome['stripe_id'] = $stripeId ?: null;
        $outcome['stripe_status'] = $stripeStatus ?: null;

        $target = WebhookEventHandler::mapStripeStatus($stripeStatus, $fromStatus);
        $outcome['to'] = $target;

        if ($target === $fromStatus) {
            $outcome['action'] = 'unchanged';

            return $outcome;
        }

        if ($dryRun) {
            $outcome['action'] = 'would_' . $target;

            return $outcome;
        }

        // Ensure the Stripe ids are stored so the lifecycle setters (which look the local
        // record up by provider id) resolve — this also heals records that only had a
        // checkout_session_id.
        if ($stripeId !== '') {
            StripeModelBridge::setSubscriptionId($subscription, $stripeId);
        }
        $customerId = (string) data_get($live, 'customer', '');
        if ($customerId !== '') {
            StripeModelBridge::setCustomerId($subscription, $customerId);
        }
        if (data_get($live, 'current_period_end')) {
            $subscription->end_date = now()->setTimestamp((int) data_get($live, 'current_period_end'));
        }
        $subscription->save();

        $lifecycle = app(SubscriptionLifecycleService::class);

        match ($target) {
            'active' => $lifecycle->approveByProviderId($stripeId, null, 'stripe'),
            'paused' => $lifecycle->suspendByProviderId($stripeId, 'stripe'),
            'canceled' => $this->markCanceled($subscription),
            'expired' => $lifecycle->expire($subscription, 'stripe'),
            default => null,
        };

        $outcome['action'] = $target;
        $outcome['changed'] = true;

        Log::info('billing.stripe_reconcile.applied', [
            'subscription_id' => $subscription->getKey(),
            'stripe_id' => $stripeId,
            'stripe_status' => $stripeStatus,
            'from' => $fromStatus,
            'to' => $target,
        ]);

        return $outcome;
    }

    /**
     * Reconcile by a Stripe subscription id (sub_...). Convenience for single-id runs.
     *
     * @param  array{dry_run?: bool}  $opts
     */
    public function reconcileByStripeId(string $stripeId, array $opts = []): ?array
    {
        $subscription = StripeModelBridge::findByStripeSubscriptionId($stripeId);
        if (! $subscription) {
            return null;
        }

        return $this->reconcile($subscription, $opts);
    }

    /**
     * Retrieve the live Stripe subscription for a local record, using its stored
     * subscription id, or falling back to the checkout session's subscription.
     */
    protected function fetchLiveSubscription(Model $subscription): mixed
    {
        $stripe = Client::make();

        $subscriptionId = StripeModelBridge::getSubscriptionId($subscription);
        if ($subscriptionId) {
            return $stripe->subscriptions->retrieve($subscriptionId, []);
        }

        $sessionId = (string) data_get($subscription->metadata, 'providers.stripe.checkout_session_id', '');
        if ($sessionId === '') {
            return null;
        }

        $session = $stripe->checkout->sessions->retrieve($sessionId, []);
        $resolvedSubscriptionId = (string) data_get($session, 'subscription', '');
        if ($resolvedSubscriptionId === '') {
            return null;
        }

        return $stripe->subscriptions->retrieve($resolvedSubscriptionId, []);
    }

    protected function markCanceled(Model $subscription): void
    {
        $metadata = is_array($subscription->metadata) ? $subscription->metadata : [];
        data_set($metadata, 'stripe_reconcile.canceled_at', now()->toIso8601String());
        data_set($metadata, 'stripe_reconcile.reason', 'stripe_reports_canceled');

        $subscription->update([
            'status' => 'canceled',
            'metadata' => $metadata,
        ]);
    }
}
