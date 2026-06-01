<?php

namespace Lyre\Billing\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Lyre\Model;

class SubscriptionEntitlement extends Model
{
    use HasFactory;

    protected $casts = [
        'metadata' => 'array',
    ];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function entitlable(): MorphTo
    {
        return $this->morphTo();
    }
}
