<?php

namespace Lyre\Billing\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Lyre\Facet\Concerns\HasFacet;
use Lyre\Model;

class SubscriptionPlan extends Model
{
    use HasFactory, HasFacet;

    protected $with = ['subscriptionPlanBillables'];

    protected array $included = [
        'plan_kind',
        'visibility',
        'entitlement_mode',
        'entitlements_config',
        'plan_summary',
        'billable_summary',
    ];

    protected $casts = [
        'features' => 'array',
        'metadata' => 'array',
        'entitlements_config' => 'array',
    ];

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }

    public function subscriptionPlanBillables()
    {
        return $this->hasMany(SubscriptionPlanBillable::class)->orderBy('order');
    }

    public function entitlements(): array
    {
        $entitlements = data_get($this->metadata, 'entitlements', []);
        return is_array($entitlements) ? $entitlements : [];
    }

    public function primaryEntitlement(): ?array
    {
        $primary = data_get($this->metadata, 'primary_entitlement');

        if (is_array($primary) && ! empty($primary['type']) && ! empty($primary['id'])) {
            return [
                'type' => (string) $primary['type'],
                'id' => (int) $primary['id'],
            ];
        }

        foreach ((array) data_get($this->entitlements_config, 'fixed', []) as $entry) {
            $typeToken = strtolower((string) data_get($entry, 'type'));
            $modelClass = config("billing.entitlements.type_map.{$typeToken}", data_get($entry, 'type'));
            $firstId = collect((array) data_get($entry, 'ids', []))
                ->map(fn ($id) => (int) $id)
                ->first(fn ($id) => $id > 0);

            if ($modelClass && class_exists($modelClass) && $firstId) {
                return [
                    'type' => $modelClass,
                    'id' => $firstId,
                ];
            }
        }

        return null;
    }

    public function getPlanKindAttribute(): string
    {
        return (string) ($this->getAttribute('kind') ?: 'per_exam');
    }

    public function getVisibilityAttribute(): string
    {
        return (string) ($this->getAttributes()['visibility'] ?? 'public');
    }

    public function getEntitlementModeAttribute(): string
    {
        return (string) ($this->getAttributes()['entitlement_mode'] ?? 'fixed');
    }

    public function getEntitlementsConfigAttribute($value): array
    {
        return $this->normalizeJsonArray($value);
    }

    public function getMetadataAttribute($value): array
    {
        return $this->normalizeJsonArray($value);
    }

    public function getPlanSummaryAttribute(): array
    {
        return [
            'kind' => $this->plan_kind,
            'visibility' => $this->visibility,
            'entitlement_mode' => $this->entitlement_mode,
            'price' => (float) $this->price,
            'currency' => $this->currency ?: 'USD',
        ];
    }

    public function getBillableSummaryAttribute(): array
    {
        return $this->subscriptionPlanBillables
            ->map(fn (SubscriptionPlanBillable $item) => [
                'id' => $item->id,
                'slug' => $item->billable?->slug,
                'name' => $item->billable?->name,
                'usage_limit' => $item->usage_limit,
                'unit_price' => $item->unit_price !== null ? (float) $item->unit_price : null,
                'metadata' => $item->billable?->metadata ?? [],
            ])
            ->values()
            ->all();
    }

    protected function normalizeJsonArray($value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        if (is_string($decoded)) {
            $decodedNested = json_decode($decoded, true);

            if (is_array($decodedNested)) {
                return $decodedNested;
            }
        }

        return [];
    }
}
