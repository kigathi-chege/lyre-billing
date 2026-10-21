<?php

namespace Lyre\Billing\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Plan benefits generated from what the plan actually sells, so pricing pages, checkout and
 * subscription details can never promise more (or less) than the configuration grants.
 * Editorial `features` stay available as separate marketing copy.
 */
class PlanBenefits
{
    public function __construct(protected PlanComposition $composition) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function forPlan(Model $plan): array
    {
        return $this->fromComponents($this->composition->components($plan));
    }

    /**
     * @param  list<array<string, mixed>>  $components
     * @return list<array<string, mixed>>
     */
    public function fromComponents(array $components): array
    {
        $benefits = [];

        foreach ($components as $component) {
            $benefits[] = $component['kind'] === PlanComposition::KIND_METER
                ? $this->meterBenefit($component)
                : $this->contentBenefit($component);
        }

        return array_values(array_filter($benefits));
    }

    /**
     * @param  array<string, mixed>  $component
     * @return array<string, mixed>|null
     */
    protected function contentBenefit(array $component): ?array
    {
        $type = (string) ($component['type'] ?? 'item');
        $plural = Str::plural($type);
        $grants = $this->capabilityPhrase($component['capabilities'] ?? []);

        if ($component['id'] !== null) {
            $record = $component['class']::query()->find($component['id']);

            if (! $record) {
                return null;
            }

            $label = $record->getAttribute('name') ?? $record->getAttribute('title') ?? $record->getAttribute('slug');
            $text = $grants ? "{$label}: {$grants}" : (string) $label;
        } elseif ($component['picks'] !== null) {
            $count = (int) $component['picks'];
            $text = 'Choose '.$count.' '.($count === 1 ? $type : $plural).($grants ? ": {$grants}" : '');
        } else {
            $text = 'Every '.$type.', including new ones'.($grants ? ": {$grants}" : '');
        }

        return [
            'kind' => PlanComposition::KIND_CONTENT,
            'text' => $text,
            'type' => $type,
            'id' => $component['id'],
            'picks' => $component['picks'],
            'capabilities' => $component['capabilities'] ?? [],
        ];
    }

    /**
     * @param  array<string, mixed>  $component
     * @return array<string, mixed>
     */
    protected function meterBenefit(array $component): array
    {
        $name = (string) ($component['name'] ?? Str::headline((string) $component['meter']));
        $unit = (string) ($component['unit'] ?? 'use');
        $limit = $component['usage_limit'];
        $period = $this->periodPhrase((string) $component['reset_period']);

        $text = $limit === null
            ? "Unlimited {$name}"
            : $limit.' '.Str::plural($unit, (int) $limit).($period ? " {$period}" : '');

        if ($component['per_resource_limit'] !== null) {
            $text .= ' (up to '.$component['per_resource_limit'].' per item)';
        }

        if ($limit !== null && $component['on_exhaustion'] === 'overage' && $component['unit_price']) {
            $text .= ', then '.number_format((float) $component['unit_price'], 2).' '.strtoupper((string) ($component['currency'] ?: 'USD')).' each';
        }

        return [
            'kind' => PlanComposition::KIND_METER,
            'text' => $text,
            'meter' => $component['meter'],
            'limit' => $limit,
            'per_resource_limit' => $component['per_resource_limit'],
            'reset_period' => $component['reset_period'],
            'on_exhaustion' => $component['on_exhaustion'],
            'unit_price' => $component['unit_price'],
        ];
    }

    /**
     * @param  list<string>  $capabilities
     */
    protected function capabilityPhrase(array $capabilities): string
    {
        $labels = (array) config('billing.entitlements.capabilities', []);

        return collect($capabilities)
            ->map(fn (string $capability) => Str::lower($labels[$capability] ?? Str::headline(str_replace('.', ' ', $capability))))
            ->join(', ', ' and ');
    }

    protected function periodPhrase(string $resetPeriod): ?string
    {
        return match ($resetPeriod) {
            'never' => 'in total',
            'day', 'daily' => 'per day',
            'week', 'weekly' => 'per week',
            'month', 'monthly' => 'per month',
            'year', 'yearly' => 'per year',
            default => 'per billing period',
        };
    }
}
