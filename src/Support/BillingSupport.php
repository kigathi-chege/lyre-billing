<?php

namespace Lyre\Billing\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class BillingSupport
{
    protected static array $columnCache = [];

    public static function userModel(): string
    {
        return config('lyre.user_model', config('auth.providers.users.model'));
    }

    public static function hasColumn(Model $model, string $column): bool
    {
        $key = $model->getConnectionName() . '|' . $model->getTable() . '|' . $column;

        if (! array_key_exists($key, self::$columnCache)) {
            self::$columnCache[$key] = Schema::connection($model->getConnectionName())
                ->hasColumn($model->getTable(), $column);
        }

        return self::$columnCache[$key];
    }

    public static function metadata(Model $model): array
    {
        $metadata = $model->getAttribute('metadata');

        return is_array($metadata) ? $metadata : [];
    }

    public static function setMetadata(Model $model, string $key, mixed $value): void
    {
        $metadata = self::metadata($model);
        $metadata[$key] = $value;
        $model->setAttribute('metadata', $metadata);
    }

    public static function getMetadata(Model $model, string $key, mixed $default = null): mixed
    {
        return self::metadata($model)[$key] ?? $default;
    }

    public static function getProviderValue(
        Model $model,
        string $provider,
        string $key,
        ?string $legacyColumn = null
    ): mixed
    {
        $providerValues = self::getMetadata($model, 'providers');
        $providerValues = is_array($providerValues) ? $providerValues : [];
        $value = data_get($providerValues, "{$provider}.{$key}");

        if ($value !== null) {
            return $value;
        }

        if ($legacyColumn) {
            return $model->getAttribute($legacyColumn);
        }

        return null;
    }

    public static function setProviderValue(
        Model $model,
        string $provider,
        string $key,
        mixed $value,
        ?string $legacyColumn = null
    ): void
    {
        $providerValues = self::getMetadata($model, 'providers');
        $providerValues = is_array($providerValues) ? $providerValues : [];
        data_set($providerValues, "{$provider}.{$key}", $value);
        self::setMetadata($model, 'providers', $providerValues);

        if ($legacyColumn && self::hasColumn($model, $legacyColumn)) {
            $model->setAttribute($legacyColumn, $value);
        }
    }

    public static function planDisplayName(Model $plan): string
    {
        return (string) (
            data_get($plan, 'billable.name')
            ?? data_get($plan, 'product.name')
            ?? $plan->getAttribute('name')
            ?? 'Subscription Plan'
        );
    }

    public static function planDisplayDescription(Model $plan): string
    {
        return (string) (
            data_get($plan, 'billable.description')
            ?? data_get($plan, 'product.description')
            ?? $plan->getAttribute('description')
            ?? self::planDisplayName($plan)
        );
    }

    public static function subscriptionUser(Model $subscription): mixed
    {
        return $subscription->relationLoaded('user') ? $subscription->getRelation('user') : $subscription->user;
    }

    public static function subscriptionName(Model $subscription): string
    {
        $user = self::subscriptionUser($subscription);

        return (string) (
            $subscription->getAttribute('name')
            ?? data_get($user, 'name')
            ?? config('app.name')
        );
    }

    public static function subscriptionEmail(Model $subscription): ?string
    {
        $user = self::subscriptionUser($subscription);

        return $subscription->getAttribute('email') ?: data_get($user, 'email');
    }

    public static function subscriptionAddress(Model $subscription): array
    {
        $user = self::subscriptionUser($subscription);
        $account = data_get($user, 'account');

        return [
            'address_line_1' => $subscription->getAttribute('address_line_1') ?? data_get($account, 'address_line_1') ?? '1234 Elm Street',
            'address_line_2' => $subscription->getAttribute('address_line_2') ?? data_get($account, 'address_line_2') ?? 'Suite 100',
            'admin_area_2' => $subscription->getAttribute('admin_area_2') ?? data_get($account, 'admin_area_2') ?? 'San Jose',
            'admin_area_1' => $subscription->getAttribute('admin_area_1') ?? data_get($account, 'admin_area_1') ?? 'CA',
            'postal_code' => $subscription->getAttribute('postal_code') ?? data_get($account, 'postal_code') ?? '95131',
            'country_code' => $subscription->getAttribute('country_code') ?? data_get($account, 'country_code') ?? 'US',
        ];
    }

    public static function hydrateSubscriptionProfile(Model $subscription, mixed $user): array
    {
        $fields = [
            'name' => data_get($user, 'name'),
            'email' => data_get($user, 'email'),
        ];

        foreach ($fields as $field => $value) {
            if ($value !== null && self::hasColumn($subscription, $field)) {
                $subscription->setAttribute($field, $value);
            }
        }

        return $fields;
    }
}
