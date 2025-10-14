<?php

namespace Lyre\Billing\Models;

use App\Models\User;

use Lyre\Billing\Scopes\OwnsScope;
// use App\Services\Paypal\Subscription as PaypalSubscription;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Lyre\Model;

class Subscription extends Model
{
    use HasFactory;

    protected $with = ['subscriptionPlan'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function subscriptionPlan()
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
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
