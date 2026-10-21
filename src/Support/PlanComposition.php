<?php

namespace Lyre\Billing\Support;

use Illuminate\Database\Eloquent\Model;
use Lyre\Billing\Models\BillableItem;
use Lyre\Billing\Models\SubscriptionPlanBillable;

/**
 * Describes what a plan sells, as a flat list of components built from its billables:
 *
 * - content components: a billable item pointing at content (`item_type` = type-map token or
 *   class, `item_id` = record id, or null for "any of this type"). For "any" items the
 *   number of customer picks comes from the plan link's usage_limit (falling back to the
 *   item's); null picks means every record of that type, as a live rule.
 * - meter components: a billable item with `item_type = meter` and `item_id = <meter key>`,
 *   whose usage_limit is the allowance included per reset period.
 *
 * Plans that predate billables (entitlements_config / primary_entitlement) are adapted into
 * the same shape so callers never branch on the legacy contract.
 */
class PlanComposition
{
    public const KIND_CONTENT = 'content';

    public const KIND_METER = 'meter';

    public const SNAPSHOT_VERSION = 1;

    /**
     * @return list<array<string, mixed>>
     */
    public function components(Model $plan): array
    {
        $components = [];

        $links = SubscriptionPlanBillable::query()
            ->where('subscription_plan_id', $plan->getKey())
            ->with('billable.billableItems')
            ->orderBy('order')
            ->get();

        foreach ($links as $link) {
            $billable = $link->billable;

            if (! $billable || ($billable->status ?? 'active') !== 'active') {
                continue;
            }

            foreach ($billable->billableItems as $item) {
                if (($item->status ?? 'active') !== 'active') {
                    continue;
                }

                $component = $this->fromItem($item, $link);

                if ($component !== null) {
                    $components[] = $component;
                }
            }
        }

        if (! collect($components)->contains('kind', self::KIND_CONTENT)) {
            array_push($components, ...$this->legacyContent($plan));
        }

        return $components;
    }

    /**
     * A point-in-time copy of the plan's components, stored on a subscription so later plan
     * edits do not change what an existing customer bought.
     *
     * @return array<string, mixed>
     */
    public function snapshot(Model $plan): array
    {
        return [
            'version' => self::SNAPSHOT_VERSION,
            'plan_id' => $plan->getKey(),
            'captured_at' => now()->toIso8601String(),
            'components' => $this->components($plan),
        ];
    }

    /**
     * Content components a customer chooses at checkout ("any exam", with a number of picks).
     *
     * @param  list<array<string, mixed>>  $components
     * @return array<string, int> token => total picks
     */
    public function selectablePicks(array $components): array
    {
        return collect($components)
            ->filter(fn (array $component) => $component['kind'] === self::KIND_CONTENT
                && $component['id'] === null
                && $component['picks'] !== null)
            ->groupBy('type')
            ->map(fn ($group) => (int) $group->sum('picks'))
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function fromItem(BillableItem $item, SubscriptionPlanBillable $link): ?array
    {
        $billable = $link->billable;
        $linkMetadata = (array) ($link->metadata ?? []);
        $itemMetadata = (array) ($item->metadata ?? []);

        $base = [
            'billable_id' => $billable->getKey(),
            'billable_slug' => $billable->slug,
            'billable_name' => $billable->name,
            'billable_item_id' => $item->getKey(),
            'name' => $item->name ?: $billable->name,
            'source' => 'billable',
        ];

        if ($item->isMeter()) {
            $unitPrice = $link->unit_price ?? $item->unit_price;

            return $base + [
                'kind' => self::KIND_METER,
                'meter' => (string) $item->item_id,
                'unit' => $itemMetadata['unit'] ?? data_get($billable->metadata, 'unit'),
                'usage_limit' => $link->usage_limit ?? $item->usage_limit,
                'per_resource_limit' => $this->nullableInt($linkMetadata['per_resource_limit'] ?? $itemMetadata['per_resource_limit'] ?? null),
                'reset_period' => $linkMetadata['reset_period'] ?? $item->reset_period ?? 'billing_cycle',
                'unit_price' => $unitPrice !== null ? (float) $unitPrice : null,
                'currency' => $item->currency,
                'on_exhaustion' => $linkMetadata['on_exhaustion'] ?? $itemMetadata['on_exhaustion'] ?? config('billing.allowances.on_exhaustion', 'upsell'),
            ];
        }

        $class = $item->contentClass();

        if (! $class) {
            return null;
        }

        $id = $item->contentId();

        return $base + [
            'kind' => self::KIND_CONTENT,
            'type' => $item->contentToken(),
            'class' => $class,
            'id' => $id,
            'picks' => $id === null ? $this->nullableInt($link->usage_limit ?? $item->usage_limit) : null,
            'capabilities' => $item->capabilities(),
            'scope' => (array) ($itemMetadata['scope'] ?? []),
        ];
    }

    /**
     * Adapt plans configured before billables existed.
     *
     * @return list<array<string, mixed>>
     */
    protected function legacyContent(Model $plan): array
    {
        $config = (array) ($plan->getAttribute('entitlements_config') ?: []);
        $kind = (string) ($plan->getAttribute('kind') ?: 'main');
        $mode = (string) ($plan->getAttribute('entitlement_mode') ?: 'fixed');
        $components = [];

        if ($kind === 'main' && $mode === 'quota') {
            foreach ((array) data_get($config, 'quota', []) as $key => $slots) {
                if (! str_ends_with((string) $key, '_slots')) {
                    continue;
                }

                $token = substr((string) $key, 0, -strlen('_slots'));
                $class = BillableItem::classForToken($token);

                if (! $class || ($slots !== 'all' && (int) $slots <= 0)) {
                    continue;
                }

                $components[] = $this->legacyComponent($token, $class, null, $slots === 'all' ? null : (int) $slots);
            }

            return $components;
        }

        foreach ((array) data_get($config, 'fixed', []) as $entry) {
            $token = strtolower((string) data_get($entry, 'type'));
            $class = BillableItem::classForToken($token);

            foreach ((array) data_get($entry, 'ids', []) as $id) {
                if ($class && (int) $id > 0) {
                    $components[] = $this->legacyComponent($token, $class, (int) $id, null);
                }
            }
        }

        $primary = method_exists($plan, 'primaryEntitlement') ? $plan->primaryEntitlement() : null;
        if (is_array($primary) && ! empty($primary['type']) && (int) ($primary['id'] ?? 0) > 0 && class_exists((string) $primary['type'])) {
            $components[] = $this->legacyComponent(BillableItem::tokenForClass($primary['type']), $primary['type'], (int) $primary['id'], null);
        }

        foreach ((array) data_get($plan->getAttribute('metadata'), 'entitlements', []) as $entry) {
            $class = (string) data_get($entry, 'type');
            $id = (int) data_get($entry, 'id');

            if ($id > 0 && class_exists($class)) {
                $components[] = $this->legacyComponent(BillableItem::tokenForClass($class), $class, $id, null);
            }
        }

        return collect($components)
            ->unique(fn (array $component) => $component['class'].'#'.$component['id'])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function legacyComponent(?string $token, string $class, ?int $id, ?int $picks): array
    {
        return [
            'billable_id' => null,
            'billable_slug' => null,
            'billable_name' => null,
            'billable_item_id' => null,
            'name' => null,
            'source' => 'legacy',
            'kind' => self::KIND_CONTENT,
            'type' => $token,
            'class' => $class,
            'id' => $id,
            'picks' => $picks,
            'capabilities' => BillableItem::defaultCapabilities($token),
            'scope' => [],
        ];
    }

    protected function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }
}
