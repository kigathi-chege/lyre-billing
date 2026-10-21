<?php

namespace Lyre\Billing\Support;

use Illuminate\Database\Eloquent\Model;
use Lyre\Billing\Models\BillableUsage;

/**
 * Outcome of consuming a metered allowance. Callers allow the action when allowed() is true;
 * otherwise toArray() carries what the UI needs to upsell.
 */
final class AllowanceDecision
{
    public const ALLOWED = 'allowed';

    public const OVERAGE = 'overage';

    public const EXHAUSTED = 'exhausted';

    public const NOT_INCLUDED = 'not_included';

    /**
     * @param  array<string, mixed>|null  $component
     */
    public function __construct(
        public readonly string $outcome,
        public readonly string $meter,
        public readonly ?Model $subscription = null,
        public readonly ?array $component = null,
        public readonly ?float $remaining = null,
        public readonly ?float $charged = null,
        public readonly ?BillableUsage $usage = null,
        public readonly bool $replayed = false,
        public readonly ?string $reason = null,
    ) {}

    public function allowed(): bool
    {
        return in_array($this->outcome, [self::ALLOWED, self::OVERAGE], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'outcome' => $this->outcome,
            'allowed' => $this->allowed(),
            'meter' => $this->meter,
            'subscription_id' => $this->subscription?->getKey(),
            'billable' => $this->component['billable_slug'] ?? null,
            'name' => $this->component['name'] ?? null,
            'limit' => $this->component['usage_limit'] ?? null,
            'per_resource_limit' => $this->component['per_resource_limit'] ?? null,
            'remaining' => $this->remaining,
            'charged' => $this->charged,
            'currency' => $this->component['currency'] ?? null,
            'reason' => $this->reason,
        ];
    }
}
