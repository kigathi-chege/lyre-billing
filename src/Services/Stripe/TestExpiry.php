<?php

namespace Lyre\Billing\Services\Stripe;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

class TestExpiry
{
    public static function allowed(): bool
    {
        return (bool) config('billing.testing.expiry_enabled', false)
            && app()->environment(['uat', 'testing', 'local', 'dev', 'development']);
    }

    public static function at(Model $subscription, bool $livemode): ?CarbonImmutable
    {
        $value = data_get($subscription->metadata, 'billing_test.expiry_at');

        return static::allowed() && ! $livemode && $value ? CarbonImmutable::parse($value) : null;
    }
}
