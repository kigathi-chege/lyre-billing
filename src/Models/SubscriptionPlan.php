<?php

namespace Lyre\Billing\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Lyre\Facet\Concerns\HasFacet;
use Lyre\Model;

class SubscriptionPlan extends Model
{
    use HasFactory, HasFacet;

    protected $with = ['subscriptionPlanBillables'];

    protected $casts = [
        'metadata' => 'array'
    ];

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }

    public function subscriptionPlanBillables()
    {
        return $this->hasMany(SubscriptionPlanBillable::class)->orderBy('order');
    }
}
