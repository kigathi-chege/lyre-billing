<?php

namespace Lyre\Billing\Services;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Lyre\Billing\Contracts\OverageBiller;
use Lyre\Billing\Models\BillableUsage;
use Lyre\Billing\Support\AllowanceDecision;
use Lyre\Billing\Support\BillingSupport;
use Lyre\Billing\Support\PlanComposition;

/**
 * Enforces metered allowances (meter components of a subscription's composition) against the
 * usage ledger in billable_usages. Two independent limits apply, whichever is reached first:
 *
 * - usage_limit per reset period (billing_cycle, day, week, month, year or never);
 * - per_resource_limit per resource (e.g. attempts per assessment) over the subscription.
 *
 * When a limit is reached the component's on_exhaustion decides: `upsell` (default) denies the
 * action; `overage` records a priced usage row and charges it through the provider's
 * OverageBiller, falling back to upsell when the provider cannot be charged.
 */
class AllowanceService
{
    /**
     * Active subscriptions of the user whose composition includes the meter.
     *
     * @return Collection<int, array{subscription: Model, component: array<string, mixed>}>
     */
    public function candidates(Authenticatable $user, string $meter): Collection
    {
        $subscriptionClass = config('billing.models.subscription');

        return $subscriptionClass::query()
            ->withoutGlobalScopes()
            ->where('user_id', $user->getAuthIdentifier())
            ->where('status', 'active')
            ->get()
            ->filter(fn (Model $subscription) => $subscription->isAccessActive())
            ->flatMap(fn (Model $subscription) => collect($subscription->accessComponents())
                ->filter(fn (array $component) => $component['kind'] === PlanComposition::KIND_METER && $component['meter'] === $meter)
                ->map(fn (array $component) => ['subscription' => $subscription, 'component' => $component]))
            ->values();
    }

    /**
     * Allowance status per subscription for a meter, for meters and upsell copy in the UI.
     *
     * @return list<array<string, mixed>>
     */
    public function summary(Authenticatable $user, string $meter, ?Model $resource = null): array
    {
        return $this->candidates($user, $meter)
            ->map(function (array $candidate) use ($resource) {
                $component = $candidate['component'];
                $subscription = $candidate['subscription'];
                $used = $this->usedInPeriod($subscription, $component);

                return [
                    'subscription_id' => $subscription->getKey(),
                    'meter' => $component['meter'],
                    'name' => $component['name'],
                    'unit' => $component['unit'] ?? null,
                    'limit' => $component['usage_limit'],
                    'used' => $used,
                    'remaining' => $component['usage_limit'] === null ? null : max(0, $component['usage_limit'] - $used),
                    'per_resource_limit' => $component['per_resource_limit'],
                    'used_for_resource' => $resource ? $this->usedForResource($subscription, $component, $resource) : null,
                    'period_starts_at' => $this->periodStart($subscription, (string) $component['reset_period'])?->toIso8601String(),
                    'on_exhaustion' => $component['on_exhaustion'],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Consume an allowance. Pass a stable idempotency key (e.g. "attempt:{id}") so retries of
     * the same action never count twice.
     *
     * @param  array<string, mixed>  $context
     */
    public function consume(
        Authenticatable $user,
        string $meter,
        float $quantity = 1,
        ?Model $resource = null,
        ?string $idempotencyKey = null,
        array $context = [],
    ): AllowanceDecision {
        $slug = $idempotencyKey ? 'usage-'.sha1($meter.'|'.$user->getAuthIdentifier().'|'.$idempotencyKey) : null;

        if ($slug && ($existing = BillableUsage::query()->where('slug', $slug)->first())) {
            return new AllowanceDecision(
                outcome: data_get($existing->metadata, 'overage') ? AllowanceDecision::OVERAGE : AllowanceDecision::ALLOWED,
                meter: $meter,
                subscription: $existing->subscription,
                usage: $existing,
                replayed: true,
            );
        }

        $candidates = $this->candidates($user, $meter)
            ->sortBy(fn (array $candidate) => $candidate['component']['usage_limit'] === null ? 0 : 1)
            ->values();

        if ($candidates->isEmpty()) {
            return new AllowanceDecision(AllowanceDecision::NOT_INCLUDED, $meter, reason: 'No active subscription includes this allowance.');
        }

        try {
            return DB::transaction(function () use ($candidates, $user, $meter, $quantity, $resource, $slug, $context) {
                $exhausted = null;

                foreach ($candidates as $candidate) {
                    $subscription = $candidate['subscription']::query()
                        ->withoutGlobalScopes()
                        ->whereKey($candidate['subscription']->getKey())
                        ->lockForUpdate()
                        ->first();
                    $component = $candidate['component'];

                    $remaining = $this->remainingFor($subscription, $component, $resource);

                    if ($remaining === null || $remaining >= $quantity) {
                        $usage = $this->record($subscription, $component, $user, $quantity, 0, $resource, $slug, $context);

                        return new AllowanceDecision(
                            AllowanceDecision::ALLOWED,
                            $meter,
                            $subscription,
                            $component,
                            remaining: $remaining === null ? null : $remaining - $quantity,
                            usage: $usage,
                        );
                    }

                    $exhausted ??= ['subscription' => $subscription, 'component' => $component, 'remaining' => $remaining];
                }

                return $this->exhausted($exhausted, $user, $meter, $quantity, $resource, $slug, $context);
            });
        } catch (QueryException $exception) {
            // A concurrent request with the same idempotency key won the unique slug.
            if ($slug && ($existing = BillableUsage::query()->where('slug', $slug)->first())) {
                return new AllowanceDecision(AllowanceDecision::ALLOWED, $meter, $existing->subscription, usage: $existing, replayed: true);
            }

            throw $exception;
        }
    }

    /**
     * Remaining quantity for the component, or null when unlimited.
     *
     * @param  array<string, mixed>  $component
     */
    public function remainingFor(Model $subscription, array $component, ?Model $resource = null): ?float
    {
        $remaining = null;

        if ($component['usage_limit'] !== null) {
            $remaining = max(0, (float) $component['usage_limit'] - $this->usedInPeriod($subscription, $component));
        }

        if ($resource && $component['per_resource_limit'] !== null) {
            $resourceRemaining = max(0, (float) $component['per_resource_limit'] - $this->usedForResource($subscription, $component, $resource));
            $remaining = $remaining === null ? $resourceRemaining : min($remaining, $resourceRemaining);
        }

        return $remaining;
    }

    /**
     * @param  array<string, mixed>  $component
     */
    public function usedInPeriod(Model $subscription, array $component): float
    {
        $periodStart = $this->periodStart($subscription, (string) $component['reset_period']);

        return (float) $this->usageQuery($subscription, $component)
            ->when($periodStart, fn ($query) => $query->where('recorded_at', '>=', $periodStart))
            ->where(fn ($query) => $query->whereNull('metadata->overage')->orWhere('metadata->overage', false))
            ->sum('quantity');
    }

    /**
     * @param  array<string, mixed>  $component
     */
    public function usedForResource(Model $subscription, array $component, Model $resource): float
    {
        return (float) $this->usageQuery($subscription, $component)
            ->where('metadata->resource_type', $resource->getMorphClass())
            ->where('metadata->resource_id', $resource->getKey())
            ->sum('quantity');
    }

    /**
     * Start of the window that usage_limit applies to; null means "never resets".
     */
    public function periodStart(Model $subscription, string $resetPeriod): ?CarbonInterface
    {
        return match ($resetPeriod) {
            'never' => null,
            'day', 'daily' => now()->startOfDay(),
            'week', 'weekly' => now()->startOfWeek(),
            'month', 'monthly' => now()->startOfMonth(),
            'year', 'yearly' => now()->startOfYear(),
            default => $this->billingPeriodStart($subscription),
        };
    }

    /**
     * Start of the subscription's current billing cycle, stepping whole cycles from its start.
     */
    public function billingPeriodStart(Model $subscription): ?CarbonInterface
    {
        $start = $subscription->effective_start_date;

        if (! $start) {
            return null;
        }

        $months = match ((string) $subscription->subscriptionPlan?->billing_cycle) {
            'quarterly' => 3,
            'semi_annually' => 6,
            'annually', 'yearly' => 12,
            'per_minute', 'per_hour', 'per_day', 'per_week' => null,
            default => 1,
        };

        $now = now();

        if ($months !== null) {
            $elapsed = (int) floor($start->diffInMonths($now) / $months) * $months;
            $periodStart = $start->copy()->addMonthsNoOverflow($elapsed);

            return $periodStart->greaterThan($now) ? $periodStart->subMonthsNoOverflow($months) : $periodStart;
        }

        $seconds = match ((string) $subscription->subscriptionPlan?->billing_cycle) {
            'per_minute' => 60,
            'per_hour' => 3600,
            'per_day' => 86400,
            default => 604800,
        };

        return $start->copy()->addSeconds((int) floor($start->diffInSeconds($now) / $seconds) * $seconds);
    }

    /**
     * @param  array{subscription: Model, component: array<string, mixed>, remaining: float}  $exhausted
     * @param  array<string, mixed>  $context
     */
    protected function exhausted(array $exhausted, Authenticatable $user, string $meter, float $quantity, ?Model $resource, ?string $slug, array $context): AllowanceDecision
    {
        ['subscription' => $subscription, 'component' => $component, 'remaining' => $remaining] = $exhausted;
        $unitPrice = (float) ($component['unit_price'] ?? 0);
        $biller = $this->overageBiller($subscription);

        // The per-resource limit is a hard cap: overage only ever extends the period allowance.
        $resourceCapped = $resource
            && $component['per_resource_limit'] !== null
            && $this->usedForResource($subscription, $component, $resource) + $quantity > $component['per_resource_limit'];

        if ($resourceCapped) {
            return new AllowanceDecision(AllowanceDecision::EXHAUSTED, $meter, $subscription, $component, $remaining, reason: 'Limit reached for this item.');
        }

        if ($component['on_exhaustion'] !== 'overage' || $unitPrice <= 0 || ! $biller) {
            return new AllowanceDecision(AllowanceDecision::EXHAUSTED, $meter, $subscription, $component, $remaining, reason: 'Allowance used up.');
        }

        $amount = round($unitPrice * $quantity, 2);
        $currency = (string) ($component['currency'] ?: $subscription->subscriptionPlan?->currency ?: 'USD');
        $usage = $this->record($subscription, $component, $user, $quantity, $amount, $resource, $slug, $context + ['overage' => true]);

        $reference = $biller->charge(
            $subscription,
            $amount,
            $currency,
            sprintf('%s overage (%s %s)', $component['name'] ?? $meter, rtrim(rtrim(number_format($quantity, 2), '0'), '.'), $component['unit'] ?? 'unit'),
            $usage->slug,
        );

        $usage->metadata = array_merge((array) $usage->metadata, ['provider_reference' => $reference]);
        $usage->save();

        Log::info('billing.allowance.overage_charged', [
            'subscription_id' => $subscription->getKey(),
            'meter' => $meter,
            'amount' => $amount,
            'currency' => $currency,
            'reference' => $reference,
        ]);

        return new AllowanceDecision(AllowanceDecision::OVERAGE, $meter, $subscription, $component, 0, $amount, $usage);
    }

    /**
     * @param  array<string, mixed>  $component
     * @param  array<string, mixed>  $context
     */
    protected function record(Model $subscription, array $component, Authenticatable $user, float $quantity, float $amount, ?Model $resource, ?string $slug, array $context): BillableUsage
    {
        return BillableUsage::query()->create(array_filter([
            'slug' => $slug,
            'billable_item_id' => $component['billable_item_id'],
            'subscription_id' => $subscription->getKey(),
            'user_id' => $user->getAuthIdentifier(),
            'quantity' => $quantity,
            'amount' => $amount,
            'recorded_at' => now(),
            'metadata' => array_filter([
                'meter' => $component['meter'],
                'resource_type' => $resource?->getMorphClass(),
                'resource_id' => $resource?->getKey(),
                ...$context,
            ], fn ($value) => $value !== null),
        ], fn ($value) => $value !== null));
    }

    /**
     * @param  array<string, mixed>  $component
     */
    protected function usageQuery(Model $subscription, array $component)
    {
        return BillableUsage::query()
            ->where('subscription_id', $subscription->getKey())
            ->where('billable_item_id', $component['billable_item_id']);
    }

    protected function overageBiller(Model $subscription): ?OverageBiller
    {
        $provider = BillingSupport::subscriptionProvider($subscription);
        $class = $provider ? config("billing.allowances.overage_billers.{$provider}") : null;

        if (! $class || ! class_exists($class)) {
            return null;
        }

        $biller = app($class);

        return $biller instanceof OverageBiller && $biller->supports($subscription) ? $biller : null;
    }
}
