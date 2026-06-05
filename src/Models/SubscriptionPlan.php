<?php

namespace Lyre\Billing\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Lyre\Facet\Concerns\HasFacet;
use Lyre\Model;

class SubscriptionPlan extends Model
{
    use HasFactory, HasFacet;

    protected $with = ['subscriptionPlanBillables', 'product'];

    protected $casts = [
        'features' => 'array',
        'metadata' => 'array',
    ];

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }

    public function subscriptionPlanBillables()
    {
        return $this->hasMany(SubscriptionPlanBillable::class)->orderBy('order');
    }

    public function product(): MorphTo
    {
        return $this->morphTo();
    }

    public function entitlements(): array
    {
        $entitlements = data_get($this->metadata, 'entitlements', []);
        return is_array($entitlements) ? $entitlements : [];
    }
}
