<?php

namespace Lyre\Billing\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Lyre\Model;

class Billable extends Model
{
    use HasFactory;

    public function billableItems()
    {
        return $this->hasMany(BillableItem::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function subscriptionPlanBillables()
    {
        return $this->hasMany(SubscriptionPlanBillable::class);
    }
}
