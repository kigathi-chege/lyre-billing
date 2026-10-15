<?php

namespace Lyre\Billing\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Lyre\Billing\Models\BillingEventAttempt;
use Lyre\Billing\Models\BillingPayment;
use Lyre\Billing\Models\BillingProviderEvent;
use Lyre\Billing\Models\BillingProviderInvoice;
use Lyre\Billing\Models\BillingStateChange;
use Lyre\Billing\Services\Stripe\BillingSynchronizer;
use Lyre\Billing\Services\Stripe\ProviderData;
use Lyre\Billing\Services\Stripe\ProviderReader;
use Lyre\Billing\Services\Stripe\StripeModelBridge;

class ProcessBillingEvent implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 90;

    public int $uniqueFor = 180;

    public function __construct(public int $eventId) {}

    public function uniqueId(): string
    {
        return (string) $this->eventId;
    }

    public function backoff(): array
    {
        return [30, 60, 180, 300];
    }

    public function handle(BillingSynchronizer $sync): void
    {
        $token = (string) Str::uuid();
        $event = DB::transaction(function () use ($token) {
            $e = BillingProviderEvent::whereKey($this->eventId)->lockForUpdate()->firstOrFail();
            if (in_array($e->status, ['applied', 'ignored', 'failed']) || ($e->lease_until && $e->lease_until->isFuture())) {
                return null;
            }
            if (BillingEventAttempt::where('event_id', $e->id)->where('generation', $e->generation)->count() >= 5) {
                $e->update(['status' => 'failed', 'reason' => 'processing_attempt_budget_exhausted', 'lease_until' => null, 'lease_token' => null]);

                return null;
            }
            BillingEventAttempt::where('event_id', $e->id)->where('status', 'processing')->update(['status' => 'abandoned', 'finished_at' => now()]);
            $e->update(['status' => 'processing', 'lease_token' => $token, 'lease_until' => now()->addMinutes(3)]);

            return $e;
        });
        if (! $event) {
            return;
        }
        $attempt = BillingEventAttempt::create(['event_id' => $event->id, 'generation' => $event->generation, 'status' => 'processing', 'started_at' => now(), 'worker' => gethostname() ?: 'unknown', 'release' => config('billing.observability.release')]);
        try {
            $object = data_get($event->payload, '_resolved_object', data_get($event->payload, 'data.object', []));
            if (! $object && ($url = data_get($event->payload, 'related_object.url'))) {
                $object = app(ProviderReader::class)->relatedObject($url, $event->account);
            }
            $type = $event->event_type;
            $supported = str_starts_with($type, 'customer.subscription.') || str_starts_with($type, 'invoice.') || str_starts_with($type, 'invoice_payment.') || str_starts_with($type, 'checkout.session.') || in_array($type, ['charge.refunded', 'charge.dispute.created', 'charge.dispute.closed', 'payment_intent.succeeded', 'payment_intent.payment_failed']);
            if (! $supported) {
                $this->finish($event, $attempt, $token, 'ignored', 'unsupported_event');

                return;
            }
            if (str_starts_with($type, 'invoice_payment.') && ! empty($object['invoice'])) {
                $object = app(ProviderReader::class)->relatedObject('/v1/invoices/'.ProviderData::id($object['invoice']), $event->account);
            }
            $providerId = str_starts_with($type, 'customer.subscription.') ? ProviderData::id($object) : ProviderData::subscriptionId($object);
            $subscription = $providerId ? StripeModelBridge::findByStripeSubscriptionId($providerId) : null;
            if (! $subscription && str_starts_with($type, 'checkout.session.')) {
                $subscription = StripeModelBridge::findByCheckoutSessionId(ProviderData::id($object));
                if ($subscription && $providerId) {
                    StripeModelBridge::setSubscriptionId($subscription, $providerId);
                    StripeModelBridge::setCustomerId($subscription, ProviderData::id($object['customer'] ?? null));
                    $subscription->save();
                }
            }
            if ($type === 'checkout.session.expired' && $subscription && ! $providerId) {
                if ($subscription->status === 'pending') {
                    DB::transaction(function () use ($subscription, $event) {
                        $subscription->update(['status' => 'canceled', 'auto_renew' => false]);
                        BillingStateChange::create(['subscription_id' => $subscription->id, 'source' => 'webhook', 'correlation_id' => $event->correlation_id, 'reason' => 'checkout_expired', 'before' => ['status' => 'pending'], 'after' => ['status' => 'canceled', 'access_reason' => 'checkout_expired']]);
                    });
                    $this->finish($event, $attempt, $token, 'applied', 'checkout_expired');
                } else {
                    $this->finish($event, $attempt, $token, 'ignored', 'stale_checkout_expiration');
                }

                return;
            }
            $forceInvoiceIds = [];
            if (! $subscription && str_starts_with($type, 'charge.')) {
                $charge = ProviderData::id($object['charge'] ?? null) ?: ProviderData::id($object);
                $payment = BillingPayment::where(['provider' => 'stripe', 'account' => $event->account, 'livemode' => $event->livemode, 'provider_payment_id' => $charge])->first();
                if ($payment) {
                    $providerInvoice = BillingProviderInvoice::find($payment->provider_invoice_id);
                    if ($providerInvoice) {
                        $forceInvoiceIds[] = $providerInvoice->provider_invoice_id;
                    }$class = config('billing.models.subscription');
                    $subscription = $class::withoutGlobalScopes()->find($payment->subscription_id);
                }
            }
            if (! $subscription) {
                // Modern invoice and payment events may omit subscription metadata.
                $customer = ProviderData::id($object['customer'] ?? null);
                if ($customer) {
                    $class = config('billing.models.subscription');
                    $query = $class::withoutGlobalScopes()->where('metadata->providers->stripe->customer_id', $customer);
                    if (str_starts_with($type, 'customer.subscription.')) {
                        $query->where('status', 'pending')->whereNull('metadata->providers->stripe->subscription_id')->whereNotNull('metadata->providers->stripe->checkout_session_id');
                    }
                    $matches = $query->get();
                    if ($matches->count() === 1) {
                        $subscription = $matches->first();
                    }
                }
            }
            if (! $subscription) {
                throw new \RuntimeException('Verified event could not be mapped to a local subscription; review required.');
            }
            $subscription->refresh();
            if ($providerId && StripeModelBridge::getSubscriptionId($subscription) && $providerId !== StripeModelBridge::getSubscriptionId($subscription)) {
                throw new \RuntimeException('Event subscription reference does not match the local subscription; review required.');
            }
            $event->update(['subscription_id' => $subscription->id]);
            if (! StripeModelBridge::getSubscriptionId($subscription)
                && $subscription->status === 'pending'
                && data_get($subscription->metadata, 'providers.stripe.checkout_session_id')) {
                $count = BillingEventAttempt::where('event_id', $event->id)->where('generation', $event->generation)->count();
                if ($count >= 5) {
                    throw new \RuntimeException('Checkout subscription reference is still unavailable after bounded retries; review required.');
                }
                $attempt->update(['status' => 'deferred', 'finished_at' => now()]);
                BillingProviderEvent::whereKey($event->id)->where('lease_token', $token)->update(['status' => 'retrying', 'reason' => 'awaiting_checkout_subscription_reference', 'lease_token' => null, 'lease_until' => now()->addSeconds(30)]);
                Log::info('billing.event.deferred', ['event_id' => $event->provider_event_id, 'subscription_id' => $subscription->id, 'correlation_id' => $event->correlation_id, 'reason' => 'awaiting_checkout_subscription_reference']);

                return;
            }
            $sync->synchronize($subscription, false, 'webhook', $event->correlation_id, $event->account, null, null, $event->livemode, str_starts_with($type, 'invoice.') ? [ProviderData::id($object)] : $forceInvoiceIds, $type);
            $this->finish($event, $attempt, $token, 'applied', null);
        } catch (\Throwable $error) {
            $count = BillingEventAttempt::where('event_id', $event->id)->where('generation', $event->generation)->count();
            $attempt->update(['status' => 'failed', 'finished_at' => now(), 'error' => get_class($error)]);
            BillingProviderEvent::whereKey($event->id)->where('lease_token', $token)->update(['status' => $count >= 5 ? 'failed' : 'retrying', 'reason' => 'processing_failed', 'lease_token' => null, 'lease_until' => now()->addSeconds(min(300, 30 * $count))]);
            Log::error('billing.event.failed', ['event_id' => $event->provider_event_id, 'subscription_id' => $event->subscription_id, 'correlation_id' => $event->correlation_id, 'exception' => $error]);
            throw $error;
        }
    }

    protected function finish($event, $attempt, string $token, string $status, ?string $reason): void
    {
        DB::transaction(function () use ($event, $attempt, $token, $status, $reason) {
            $event->newQuery()->whereKey($event->id)->where('lease_token', $token)->update(['status' => $status, 'reason' => $reason, 'completed_at' => now(), 'lease_until' => null, 'lease_token' => null]);
            $attempt->update(['status' => $status, 'finished_at' => now()]);
        });
        Log::info('billing.event.'.$status, ['event_id' => $event->provider_event_id, 'subscription_id' => $event->subscription_id, 'correlation_id' => $event->correlation_id, 'reason' => $reason]);
    }

    public function failed(\Throwable $error): void
    {
        BillingProviderEvent::whereKey($this->eventId)->whereNotIn('status', ['applied', 'ignored'])->where(fn ($q) => $q->whereNull('lease_until')->orWhere('lease_until', '<=', now()))->update(['status' => 'failed', 'reason' => 'worker_exhausted_retries', 'lease_until' => null, 'lease_token' => null]);
    }
}
