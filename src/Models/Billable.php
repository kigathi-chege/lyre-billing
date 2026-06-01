<?php

namespace Lyre\Billing\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Lyre\Model;
use Lyre\Billing\Support\BillingSupport;

class Billable extends Model
{
    use HasFactory;

    public function billableItems()
    {
        return $this->hasMany(BillableItem::class);
    }

    public function user()
    {
        return $this->belongsTo(BillingSupport::userModel());
    }

    public function subscriptionPlanBillables()
    {
        return $this->hasMany(SubscriptionPlanBillable::class);
    }
}
