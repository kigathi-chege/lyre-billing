<?php

namespace Lyre\Billing\Support;

use Closure;
use Illuminate\Validation\Rule;
use Lyre\Billing\Models\BillableItem;

/**
 * Validation rules shared by the store/update requests of the billing catalog.
 */
class BillingRules
{
    public const RESET_PERIODS = ['billing_cycle', 'never', 'day', 'week', 'month', 'year'];

    public const ON_EXHAUSTION = ['upsell', 'overage'];

    public const PRICING_MODELS = ['free', 'fixed', 'usage_based', 'quota_based'];

    /**
     * @return array<string, mixed>
     */
    public static function billable(bool $updating = false): array
    {
        $required = $updating ? ['sometimes', 'required'] : ['required'];

        return [
            'name' => [...$required, 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'metadata' => ['nullable', 'array'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function billableItem(bool $updating = false): array
    {
        $required = $updating ? ['sometimes', 'required'] : ['required'];
        $prefix = config('lyre.table_prefix');

        return [
            'billable_id' => [...$required, 'exists:'.$prefix.'billables,id'],
            'name' => ['nullable', 'string', 'max:255'],
            'pricing_model' => ['nullable', Rule::in(self::PRICING_MODELS)],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'item_type' => [...$required, 'string', self::itemTypeRule()],
            'item_id' => ['nullable', 'required_if:item_type,'.BillableItem::TYPE_METER.','.BillableItem::TYPE_FUNCTION.','.BillableItem::TYPE_MODEL, 'string', 'max:255', self::itemIdRule()],
            'usage_limit' => ['nullable', 'integer', 'min:0'],
            'unit_price' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'reset_period' => ['nullable', Rule::in(self::RESET_PERIODS)],
            ...self::allowanceMetadata(),
            'metadata.capabilities' => ['nullable', 'array'],
            'metadata.capabilities.*' => ['string', 'max:100'],
            'metadata.scope' => ['nullable', 'array'],
            'metadata.unit' => ['nullable', 'string', 'max:50'],
        ];
    }

    /**
     * Per-plan allowance settings stored on subscription_plan_billables.metadata or on items.
     *
     * @return array<string, mixed>
     */
    public static function allowanceMetadata(): array
    {
        return [
            'metadata' => ['nullable', 'array'],
            'metadata.on_exhaustion' => ['nullable', Rule::in(self::ON_EXHAUSTION)],
            'metadata.per_resource_limit' => ['nullable', 'integer', 'min:0'],
            'metadata.reset_period' => ['nullable', Rule::in(self::RESET_PERIODS)],
        ];
    }

    protected static function itemTypeRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $value = (string) $value;
            $special = [BillableItem::TYPE_METER, BillableItem::TYPE_FUNCTION, BillableItem::TYPE_MODEL];

            if (in_array($value, $special, true) || BillableItem::classForToken($value) || BillableItem::tokenForClass($value)) {
                return;
            }

            $fail("The {$attribute} must be a meter, a function, or a sellable content type.");
        };
    }

    protected static function itemIdRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $type = (string) request()->input('item_type');

            if (in_array($type, [BillableItem::TYPE_METER, BillableItem::TYPE_FUNCTION, BillableItem::TYPE_MODEL], true)) {
                if (blank($value)) {
                    $fail("The {$attribute} is required for {$type} items.");
                }

                return;
            }

            $class = BillableItem::classForToken($type) ?? (BillableItem::tokenForClass($type) ? $type : null);

            if ($class && filled($value) && ! $class::query()->whereKey((int) $value)->exists()) {
                $fail("The selected {$attribute} does not exist.");
            }
        };
    }
}
