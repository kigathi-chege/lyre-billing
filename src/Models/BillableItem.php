<?php

namespace Lyre\Billing\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Lyre\Model;

class BillableItem extends Model
{
    use HasFactory;

    protected $casts = [
        'metadata' => 'array',
    ];

    public function billable()
    {
        return $this->belongsTo(Billable::class);
    }

    public function item(): MorphTo
    {
        return $this->morphTo();
    }

    public function billableUsages()
    {
        return $this->hasMany(BillableUsage::class);
    }

    /**
     * Metered allowance item: item_type = meter, item_id = meter key (e.g. exam_attempts).
     */
    public const TYPE_METER = 'meter';

    /**
     * Legacy function-call item recorded through the #[Billable] attribute helpers.
     */
    public const TYPE_FUNCTION = 'function';

    /**
     * Legacy "whole model" item: item_id holds the model class.
     */
    public const TYPE_MODEL = 'model';

    public function isMeter(): bool
    {
        return $this->item_type === self::TYPE_METER;
    }

    /**
     * The content class this item grants, from a type-map token, a class name, or the legacy
     * "model" form. Null for meters, functions and unresolvable types.
     */
    public function contentClass(): ?string
    {
        if (in_array($this->item_type, [self::TYPE_METER, self::TYPE_FUNCTION, null, ''], true)) {
            return null;
        }

        if ($this->item_type === self::TYPE_MODEL) {
            return class_exists((string) $this->item_id) ? (string) $this->item_id : null;
        }

        return self::classForToken((string) $this->item_type)
            ?? (class_exists((string) $this->item_type) ? (string) $this->item_type : null);
    }

    public function contentToken(): ?string
    {
        $class = $this->contentClass();

        return $class ? self::tokenForClass($class) : null;
    }

    /**
     * Specific record id, or null when the item means "any record of this type".
     */
    public function contentId(): ?int
    {
        if ($this->item_type === self::TYPE_MODEL || $this->item_id === null || $this->item_id === '') {
            return null;
        }

        return (int) $this->item_id;
    }

    /**
     * Capabilities the item grants (e.g. notes.read, assessments.sit). Items may list their own
     * in metadata; otherwise the host app's defaults for the content type apply.
     *
     * @return list<string>
     */
    public function capabilities(): array
    {
        $listed = data_get($this->metadata, 'capabilities');

        return is_array($listed) ? array_values($listed) : self::defaultCapabilities($this->contentToken());
    }

    public static function classForToken(?string $token): ?string
    {
        $class = $token ? config('billing.entitlements.type_map.'.strtolower($token)) : null;

        return $class && class_exists($class) ? $class : null;
    }

    public static function tokenForClass(string $class): ?string
    {
        $token = array_search($class, (array) config('billing.entitlements.type_map', []), true);

        return $token === false ? null : (string) $token;
    }

    /**
     * @return list<string>
     */
    public static function defaultCapabilities(?string $token): array
    {
        return array_values((array) config('billing.entitlements.default_capabilities.'.($token ?? ''), []));
    }
}
