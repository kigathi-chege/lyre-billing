<?php

namespace Lyre\Billing\Models;

use Lyre\Billing\Scopes\OwnsScope;
// use App\Services\Paypal\Subscription as PaypalSubscription;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Lyre\Model;
use Lyre\Billing\Support\BillingSupport;

class Subscription extends Model
{
    use HasFactory;

    protected $with = ['subscriptionPlan'];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(BillingSupport::userModel());
    }

    public function subscriptionPlan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    public function subscriptionEntitlements()
    {
        return $this->hasMany(SubscriptionEntitlement::class);
    }

    // public function paypalSubscription()
    // {
    //     return PaypalSubscription::fromAspireSubscription($this);
    // }

    public static function booted()
    {
        static::addGlobalScope(new OwnsScope);
    }
}
