<?php

namespace Lyre\Billing\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Lyre\Billing\Events\SubscriptionProcessStarted;
use Lyre\Billing\Models\BillableItem;
use Lyre\Billing\Models\Invoice;
use Lyre\Billing\Models\SubscriptionEntitlement;
use Lyre\Billing\Support\BillingSupport;
use Lyre\Billing\Support\PlanComposition;
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

        // Validate the customer's picks before any subscription, invoice or provider checkout
        // exists, so an invalid selection never leaves a payable checkout behind.
        $requestedEntitlements = $this->resolveRequestedEntitlements($plan);

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

        /** @var Model $subscription */
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

        // Record what is being bought; renewals keep it so later plan edits do not change it.
        $metadata = (array) ($subscription->getAttribute('metadata') ?? []);
        $metadata['access_snapshot'] = app(PlanComposition::class)->snapshot($plan);
        $subscription->setAttribute('metadata', $metadata);

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
        $this->syncCompatibilityEntitlements($plan, $subscription, $requestedEntitlements);
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
        $prototype = new $subscriptionClass;

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

        $invoice->invoice_number = 'INV-'.now()->format('Ym').'-'.str_pad((string) $invoice->id, 6, '0', STR_PAD_LEFT);
        $invoice->saveQuietly();

        Log::info('billing.plan_subscription.invoice_number_backfilled', [
            'invoice_id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
        ]);
    }

    /**
     * Entitlement sources written from the plan at checkout. A pending subscription is reused
     * across checkout attempts, so these are replaced (never accumulated) on each attempt.
     */
    public const PLAN_ENTITLEMENT_SOURCES = ['plan_fixed', 'plan_quota', 'plan_quota_all', 'plan_primary_entitlement', 'plan'];

    /**
     * Write the plan's entitlement rows for a checkout. Rows on a pending subscription confer
     * nothing (access requires an active subscription); they record what is being bought.
     */
    protected function syncCompatibilityEntitlements(Model $plan, Model $subscription, ?Collection $requestedEntitlements = null): void
    {
        $requestedEntitlements ??= $this->resolveRequestedEntitlements($plan);

        if ($subscription->getAttribute('status') === 'pending') {
            SubscriptionEntitlement::query()
                ->where('subscription_id', $subscription->getKey())
                ->whereIn('source', self::PLAN_ENTITLEMENT_SOURCES)
                ->delete();
        }

        foreach ($requestedEntitlements as $entitlement) {
            SubscriptionEntitlement::firstOrCreate([
                'subscription_id' => $subscription->getKey(),
                'entitlable_type' => $entitlement['type'],
                'entitlable_id' => $entitlement['id'],
            ], [
                'source' => $entitlement['source'],
            ]);
        }
    }

    /**
     * Entitlement rows for a checkout, from the plan's composition: fixed content items, the
     * customer's validated picks for "any" items, and (for access checks that still read rows)
     * the current records of unlimited "any" items.
     *
     * @return Collection<int, array{type: string, id: int, source: string}>
     */
    protected function resolveRequestedEntitlements(Model $plan): Collection
    {
        $composition = app(PlanComposition::class);
        $components = $composition->components($plan);
        $selectable = $composition->selectablePicks($components);
        $selections = $this->resolveRequestedSelections();
        $entitlements = collect();

        foreach ($selections as $typeToken => $keys) {
            $modelClass = BillableItem::classForToken($typeToken);

            if (! $modelClass || ! array_key_exists($typeToken, $selectable)) {
                throw CommonException::fromMessage("This plan does not offer {$typeToken} selections.", 422);
            }

            if (count($keys) > $selectable[$typeToken]) {
                throw CommonException::fromMessage("Too many {$typeToken} selections for this plan.", 422);
            }

            foreach ($this->resolveSelectionIds($modelClass, $keys, $typeToken) as $id) {
                $entitlements->push(['type' => $modelClass, 'id' => $id, 'source' => 'plan_quota']);
            }
        }

        foreach ($selectable as $typeToken => $picks) {
            if (! array_key_exists($typeToken, $selections)) {
                throw CommonException::fromMessage("Choose up to {$picks} {$typeToken} item(s) for this plan.", 422);
            }
        }

        foreach ($components as $component) {
            if ($component['kind'] !== PlanComposition::KIND_CONTENT) {
                continue;
            }

            if ($component['id'] !== null) {
                $entitlements->push(['type' => $component['class'], 'id' => $component['id'], 'source' => 'plan_fixed']);

                continue;
            }

            if ($component['picks'] === null && config('billing.entitlements.expand_unlimited', true)) {
                foreach ($component['class']::query()->pluck('id') as $id) {
                    $entitlements->push(['type' => $component['class'], 'id' => (int) $id, 'source' => 'plan_quota_all']);
                }
            }
        }

        return $entitlements->unique(fn ($item) => $item['type'].'-'.$item['id'])->values();
    }

    /**
     * Resolve selection keys (numeric ids or slugs) to existing record ids; every key must match.
     *
     * @param  list<string>  $keys
     * @return list<int>
     */
    protected function resolveSelectionIds(string $modelClass, array $keys, string $typeToken): array
    {
        $model = new $modelClass;
        $numeric = array_values(array_filter($keys, fn ($key) => ctype_digit($key)));
        $slugs = array_values(array_diff($keys, $numeric));

        $records = $modelClass::query()->where(function ($builder) use ($numeric, $slugs, $model) {
            if ($numeric !== []) {
                $builder->orWhereIn($model->getQualifiedKeyName(), array_map('intval', $numeric));
            }
            if ($slugs !== []) {
                $builder->orWhereIn($model->qualifyColumn('slug'), $slugs);
            }
        })->get();

        $matched = $records->flatMap(fn ($record) => [(string) $record->getKey(), (string) $record->getAttribute('slug')])->all();
        $unknown = array_values(array_diff($keys, $matched));

        if ($unknown !== []) {
            throw CommonException::fromMessage("Unknown {$typeToken} selection: ".implode(', ', $unknown), 422);
        }

        return $records->map(fn ($record) => (int) $record->getKey())->unique()->values()->all();
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
                ->map(fn ($id) => trim((string) $id))
                ->filter(fn ($id) => $id !== '' && $id !== '0')
                ->unique()
                ->values()
                ->all();

            if ($normalizedIds !== []) {
                $selections[strtolower((string) $typeToken)] = $normalizedIds;
            }
        }

        return $selections;
    }
}
