<?php

namespace Lyre\Billing\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Lyre\Model;

class SubscriptionProduct extends Model
{
    use HasFactory;

    public function subscription()
    {
        return $this->belongsTo(Subscription::class);
    }

    public function product(): MorphTo
    {
        return $this->morphTo();
    }
}
