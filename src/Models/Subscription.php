<?php

namespace Lyre\Billing\Models;

use Carbon\CarbonInterface;
use Lyre\Scopes\OwnsScope;
// use App\Services\Paypal\Subscription as PaypalSubscription;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Lyre\Model;
use Lyre\Billing\Support\BillingSupport;

class Subscription extends Model
{
    use HasFactory;

    protected array $included = [
        'provider_summary',
        'entitlement_summary',
        'is_access_active',
        'is_renewable',
        'effective_start_date',
        'effective_end_date',
    ];

    protected $with = ['subscriptionPlan'];

    protected $casts = [
        'metadata' => 'array',
        'start_date' => 'datetime',
        'end_date' => 'datetime',
        'auto_renew' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(BillingSupport::userModel());
    }

    public function subscriptionPlan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    public function subscriptionEntitlements()
    {
        return $this->hasMany(SubscriptionEntitlement::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(config('billing.models.invoice', Invoice::class));
    }

    // public function paypalSubscription()
    // {
    //     return PaypalSubscription::fromAspireSubscription($this);
    // }

    public static function booted()
    {
        static::addGlobalScope(new OwnsScope);
    }

    public function isAccessActive(): bool
    {
        if ($this->status !== 'active') {
            return false;
        }

        $coverageStart = $this->resolveCoverageStartDate();
        $coverageEnd = $this->resolveCoverageEndDate();

        if ($coverageStart && $coverageStart->isFuture()) {
            return false;
        }

        return ! $coverageEnd || ! $coverageEnd->isPast();
    }

    public function getIsAccessActiveAttribute(): bool
    {
        return $this->isAccessActive();
    }

    public function getIsRenewableAttribute(): bool
    {
        if ($this->status !== 'active') {
            return false;
        }

        $coverageEnd = $this->resolveCoverageEndDate();

        return ! $coverageEnd || ! $coverageEnd->isPast();
    }

    public function getEffectiveStartDateAttribute(): ?CarbonInterface
    {
        return $this->resolveCoverageStartDate();
    }

    public function getEffectiveEndDateAttribute(): ?CarbonInterface
    {
        return $this->resolveCoverageEndDate();
    }

    public function getEntitlementSummaryAttribute(): string
    {
        $labels = $this->resolveEntitlementLabels();

        if ($labels->isNotEmpty()) {
            return $labels->implode(', ');
        }

        return (string) ($this->subscriptionPlan?->name ?? 'Subscription access');
    }

    public function getProviderSummaryAttribute(): string
    {
        $providerKey = $this->resolveProviderKey();
        if ($providerKey) {
            return match ($providerKey) {
                'paypal' => 'PayPal',
                'stripe' => 'Stripe',
                default => ucfirst($providerKey),
            };
        }

        $transaction = $this->invoices()
            ->with('transactions.paymentMethod')
            ->latest('id')
            ->first()?->transactions
            ?->sortByDesc('id')
            ->first();

        $paymentMethod = $transaction?->paymentMethod;
        if ($paymentMethod) {
            return (string) ($paymentMethod->name ?? $paymentMethod->provider ?? 'Billing provider');
        }

        return 'Billing provider';
    }

    protected function resolveEntitlementLabels(): Collection
    {
        $entitlements = $this->relationLoaded('subscriptionEntitlements')
            ? $this->subscriptionEntitlements
            : $this->subscriptionEntitlements()->get();

        // Skip entitlements whose morph target class no longer exists (e.g. a
        // removed model left dangling in the type column). Resolving such a row
        // would fatal ("Class ... not found") and break serialization of the
        // whole subscription. Load the morph only for the surviving rows.
        $resolvable = $entitlements
            ->filter(fn ($entitlement) => $entitlement->entitlable_type && class_exists($entitlement->entitlable_type))
            ->values();

        $resolvable->loadMissing('entitlable');

        return $resolvable
            ->map(fn ($entitlement) => $entitlement->entitlable?->name ?? $entitlement->entitlable?->title ?? $entitlement->entitlable?->slug)
            ->filter()
            ->unique()
            ->values();
    }

    protected function resolveProviderKey(): ?string
    {
        foreach (['paypal', 'stripe'] as $provider) {
            $providerReference = BillingSupport::getProviderValue($this, $provider, 'subscription_id');

            if ($providerReference) {
                return $provider;
            }
        }

        $providers = data_get($this->metadata, 'providers');
        if (is_array($providers)) {
            $firstProvider = array_key_first($providers);
            return is_string($firstProvider) ? $firstProvider : null;
        }

        return null;
    }

    protected function resolveCoverageStartDate(): ?CarbonInterface
    {
        if ($this->start_date) {
            return $this->start_date;
        }

        if ($this->status === 'active' && $this->created_at instanceof CarbonInterface) {
            return $this->created_at;
        }

        return null;
    }

    protected function resolveCoverageEndDate(): ?CarbonInterface
    {
        if ($this->end_date) {
            return $this->end_date;
        }

        $coverageStart = $this->resolveCoverageStartDate();

        if ($this->status === 'active' && $coverageStart) {
            $billingCycle = $this->subscriptionPlan?->billing_cycle;

            return match ($billingCycle) {
                'annually', 'yearly' => $coverageStart->copy()->addYear(),
                default => $coverageStart->copy()->addMonth(),
            };
        }

        return null;
    }
}
