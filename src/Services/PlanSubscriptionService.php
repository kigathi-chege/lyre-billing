<?php

namespace Lyre\Billing\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Lyre\Billing\Events\SubscriptionProcessStarted;
use Lyre\Billing\Models\Invoice;
use Lyre\Billing\Models\SubscriptionEntitlement;
use Lyre\Billing\Support\BillingSupport;
use Lyre\Exceptions\CommonException;

class PlanSubscriptionService
{
    public function subscribeToPlan(Model $plan, ?Authenticatable $user = null, ?string $provider = null): array
    {
        $user ??= auth()->user();

        if (! $user) {
            throw CommonException::fromMessage('You must be logged in to subscribe to this plan.', 403);
        }

        $provider = strtolower((string) ($provider ?: config('billing.subscriptions.provider', 'paypal')));
        Log::info('billing.plan_subscription.start', [
            'plan_id' => $plan->getKey(),
            'plan_class' => get_class($plan),
            'user_id' => $user->getAuthIdentifier(),
            'provider' => $provider,
            'billing_cycle' => $plan->getAttribute('billing_cycle'),
            'price' => $plan->getAttribute('price'),
        ]);

        $subscriptionClass = method_exists($plan, 'subscriptions')
            ? get_class($plan->subscriptions()->getModel())
            : config('billing.models.subscription');
        Log::info('billing.plan_subscription.subscription_model_resolved', [
            'plan_id' => $plan->getKey(),
            'subscription_class' => $subscriptionClass,
        ]);

        $existingActiveSubscription = $subscriptionClass::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->where('subscription_plan_id', $plan->getKey())
            ->where('status', 'active')
            ->latest('id')
            ->first();

        $alreadyHasActiveAccess = $existingActiveSubscription
            ? (method_exists($existingActiveSubscription, 'isAccessActive')
                ? $existingActiveSubscription->isAccessActive()
                : true)
            : false;
        Log::info('billing.plan_subscription.existing_active_check', [
            'plan_id' => $plan->getKey(),
            'existing_subscription_id' => $existingActiveSubscription?->getKey(),
            'existing_status' => $existingActiveSubscription?->getAttribute('status'),
            'already_has_active_access' => $alreadyHasActiveAccess,
        ]);

        if ($alreadyHasActiveAccess) {
            throw CommonException::fromMessage('You are already subscribed to this plan.');
        }

        /** @var \Illuminate\Database\Eloquent\Model $subscription */
        $subscription = $subscriptionClass::firstOrCreate(
            [
                'user_id' => $user->getAuthIdentifier(),
                'subscription_plan_id' => $plan->getKey(),
                'status' => 'pending',
            ],
            $this->subscriptionDefaults($plan, $user)
        );
        Log::info('billing.plan_subscription.pending_subscription_resolved', [
            'plan_id' => $plan->getKey(),
            'subscription_id' => $subscription->getKey(),
            'was_recently_created' => $subscription->wasRecentlyCreated,
            'status' => $subscription->getAttribute('status'),
            'start_date' => optional($subscription->getAttribute('start_date'))?->toIso8601String(),
            'end_date' => optional($subscription->getAttribute('end_date'))?->toIso8601String(),
        ]);

        BillingSupport::hydrateSubscriptionProfile($subscription, $user);
        BillingSupport::setProviderValue($subscription, $provider, 'selected_at', now()->toIso8601String());
        Log::info('billing.plan_subscription.before_subscription_save', [
            'subscription_id' => $subscription->getKey(),
            'provider' => $provider,
            'metadata_providers' => array_keys((array) data_get($subscription, 'metadata.providers', [])),
        ]);
        $subscription->save();
        Log::info('billing.plan_subscription.after_subscription_save', [
            'subscription_id' => $subscription->getKey(),
            'provider' => $provider,
            'metadata_providers' => array_keys((array) data_get($subscription->fresh(), 'metadata.providers', [])),
        ]);

        $invoice = $this->resolvePendingInvoice($subscription, $plan);
        Log::info('billing.plan_subscription.invoice_resolved', [
            'subscription_id' => $subscription->getKey(),
            'invoice_id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'invoice_status' => $invoice->status,
            'invoice_amount' => $invoice->amount,
        ]);

        $links = $this->startProviderCheckout($provider, $plan, $subscription, $invoice);
        Log::info('billing.plan_subscription.provider_checkout_started', [
            'subscription_id' => $subscription->getKey(),
            'provider' => $provider,
            'approval_href' => collect($links)->firstWhere('rel', 'approve')['href'] ?? null,
            'links_count' => count($links),
        ]);
        event(new SubscriptionProcessStarted(
            $subscription->fresh(),
            $plan,
            $invoice->fresh(),
            $provider
        ));
        $this->syncCompatibilityEntitlements($plan, $subscription);
        Log::info('billing.plan_subscription.entitlements_synced', [
            'subscription_id' => $subscription->getKey(),
            'provider' => $provider,
        ]);

        return [
            'subscription' => $subscription->fresh(),
            'invoice' => $invoice->fresh(),
            'links' => $links,
        ];
    }

    protected function subscriptionDefaults(Model $plan, Authenticatable $user): array
    {
        $defaults = [
            // Local Aspire schema still requires a non-null start date even before provider approval.
            'start_date' => now(),
            'auto_renew' => true,
            'end_date' => null,
        ];

        $subscriptionClass = config('billing.models.subscription');
        $prototype = new $subscriptionClass();

        foreach (['name', 'email'] as $field) {
            if (BillingSupport::hasColumn($prototype, $field) && data_get($user, $field)) {
                $defaults[$field] = data_get($user, $field);
            }
        }

        return $defaults;
    }

    protected function startProviderCheckout(
        string $provider,
        Model $plan,
        Model $subscription,
        Invoice $invoice
    ): array {
        $serviceClass = config("billing.providers.{$provider}.plan_subscription_service");

        if (! $serviceClass || ! class_exists($serviceClass)) {
            throw CommonException::fromMessage("Unsupported subscription provider [{$provider}].", 422);
        }

        Log::info('billing.plan_subscription.provider_service_resolved', [
            'provider' => $provider,
            'service_class' => $serviceClass,
            'subscription_id' => $subscription->getKey(),
            'invoice_id' => $invoice->id,
        ]);

        return app($serviceClass)->startCheckout($plan, $subscription, $invoice);
    }

    protected function resolvePendingInvoice(Model $subscription, Model $plan): Invoice
    {
        $invoice = Invoice::query()
            ->where('subscription_id', $subscription->getKey())
            ->latest('id')
            ->first();
        Log::info('billing.plan_subscription.pending_invoice_lookup', [
            'subscription_id' => $subscription->getKey(),
            'invoice_id' => $invoice?->id,
            'invoice_status' => $invoice?->status,
            'invoice_amount' => $invoice?->amount,
        ]);

        if ($invoice && $invoice->status !== 'paid') {
            if ((float) $invoice->amount !== (float) $plan->price) {
                Log::info('billing.plan_subscription.pending_invoice_amount_adjust', [
                    'invoice_id' => $invoice->id,
                    'from_amount' => $invoice->amount,
                    'to_amount' => $plan->price,
                ]);
                $invoice->amount = $plan->price;
                $invoice->save();
            }

            $this->ensureInvoiceNumber($invoice);

            return $invoice;
        }

        $invoice = Invoice::create([
            'amount' => $plan->price,
            'subscription_id' => $subscription->getKey(),
            'metadata' => [
                'provider' => data_get($subscription, 'metadata.providers')
                    ? array_key_first((array) data_get($subscription, 'metadata.providers'))
                    : null,
                ],
        ]);

        $this->ensureInvoiceNumber($invoice);

        Log::info('billing.plan_subscription.pending_invoice_created', [
            'subscription_id' => $subscription->getKey(),
            'invoice_id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'provider' => data_get($invoice, 'metadata.provider'),
        ]);

        return $invoice;
    }

    protected function ensureInvoiceNumber(Invoice $invoice): void
    {
        if ($invoice->invoice_number) {
            return;
        }

        $invoice->invoice_number = 'INV-' . now()->format('Ym') . '-' . str_pad((string) $invoice->id, 6, '0', STR_PAD_LEFT);
        $invoice->saveQuietly();

        Log::info('billing.plan_subscription.invoice_number_backfilled', [
            'invoice_id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
        ]);
    }

    protected function syncCompatibilityEntitlements(Model $plan, Model $subscription): void
    {
        $requestedEntitlements = $this->resolveRequestedEntitlements($plan);
        if ($requestedEntitlements->isNotEmpty()) {
            foreach ($requestedEntitlements as $entitlement) {
                SubscriptionEntitlement::firstOrCreate([
                    'subscription_id' => $subscription->getKey(),
                    'entitlable_type' => $entitlement['type'],
                    'entitlable_id' => $entitlement['id'],
                ], [
                    'source' => $entitlement['source'],
                ]);
            }

            return;
        }

        $primaryEntitlement = method_exists($plan, 'primaryEntitlement')
            ? $plan->primaryEntitlement()
            : null;

        if (is_array($primaryEntitlement) && ! empty($primaryEntitlement['type']) && ! empty($primaryEntitlement['id'])) {
            SubscriptionEntitlement::firstOrCreate([
                'subscription_id' => $subscription->getKey(),
                'entitlable_type' => (string) $primaryEntitlement['type'],
                'entitlable_id' => (int) $primaryEntitlement['id'],
            ], [
                'source' => 'plan_primary_entitlement',
            ]);

            return;
        }

        // Plan-level entitlement presets for package-native usage.
        $presetEntitlements = data_get($plan, 'metadata.entitlements', []);
        if (! is_array($presetEntitlements)) {
            return;
        }

        foreach ($presetEntitlements as $entitlement) {
            $type = data_get($entitlement, 'type');
            $id = (int) data_get($entitlement, 'id');
            if (! $type || $id <= 0 || ! class_exists($type)) {
                continue;
            }

            SubscriptionEntitlement::firstOrCreate([
                'subscription_id' => $subscription->getKey(),
                'entitlable_type' => $type,
                'entitlable_id' => $id,
            ], [
                'source' => 'plan',
            ]);
        }
    }

    protected function resolveRequestedEntitlements(Model $plan)
    {
        $entitlements = collect();
        $kind = (string) ($plan->getAttribute('kind') ?: 'per_exam');
        $mode = (string) ($plan->getAttribute('entitlement_mode') ?: 'fixed');
        $config = $plan->getAttribute('entitlements_config');
        $config = is_array($config) ? $config : [];

        if ($kind === 'main' && $mode === 'fixed') {
            foreach ((array) data_get($config, 'fixed', []) as $entry) {
                $typeToken = strtolower((string) data_get($entry, 'type'));
                $modelClass = config("billing.entitlements.type_map.{$typeToken}");
                $ids = collect((array) data_get($entry, 'ids', []))
                    ->map(fn ($id) => (int) $id)
                    ->filter(fn ($id) => $id > 0);

                if (! $modelClass || ! class_exists($modelClass)) {
                    continue;
                }

                foreach ($ids as $id) {
                    $entitlements->push([
                        'type' => $modelClass,
                        'id' => $id,
                        'source' => 'plan_fixed',
                    ]);
                }
            }
        }

        if ($kind === 'main' && $mode === 'quota') {
            foreach ($this->resolveRequestedSelections() as $typeToken => $ids) {
                $limit = data_get($config, "quota.{$typeToken}_slots");
                if ($limit !== 'all' && $limit !== null && count($ids) > (int) $limit) {
                    throw CommonException::fromMessage("Too many {$typeToken} selections for this plan.", 422);
                }

                $modelClass = config("billing.entitlements.type_map.{$typeToken}");
                if (! $modelClass || ! class_exists($modelClass)) {
                    continue;
                }

                foreach ($ids as $id) {
                    $entitlements->push([
                        'type' => $modelClass,
                        'id' => (int) $id,
                        'source' => 'plan_quota',
                    ]);
                }
            }

            foreach ((array) data_get($config, 'quota', []) as $quotaKey => $limit) {
                if ($limit !== 'all' || ! str_ends_with((string) $quotaKey, '_slots')) {
                    continue;
                }

                $typeToken = str_replace('_slots', '', (string) $quotaKey);
                $modelClass = config("billing.entitlements.type_map.{$typeToken}");
                if (! $modelClass || ! class_exists($modelClass)) {
                    continue;
                }

                $ids = $modelClass::query()->pluck('id');
                foreach ($ids as $id) {
                    $entitlements->push([
                        'type' => $modelClass,
                        'id' => (int) $id,
                        'source' => 'plan_quota_all',
                    ]);
                }
            }
        }

        if ($kind === 'main' && $mode === 'quota' && $entitlements->isEmpty()) {
            return collect();
        }

        return $entitlements->unique(fn ($item) => $item['type'].'-'.$item['id'])->values();
    }

    protected function resolveRequestedSelections(): array
    {
        $selectionPayload = request()->query('selection', request()->input('selection', []));
        if (! is_array($selectionPayload)) {
            return [];
        }

        $selections = [];

        foreach ($selectionPayload as $typeToken => $ids) {
            $normalizedIds = collect(is_array($ids) ? $ids : explode(',', (string) $ids))
                ->map(fn ($id) => (int) $id)
                ->filter(fn ($id) => $id > 0)
                ->values()
                ->all();

            if ($normalizedIds !== []) {
                $selections[strtolower((string) $typeToken)] = $normalizedIds;
            }
        }

        return $selections;
    }
}
