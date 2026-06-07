<?php

namespace Lyre\Billing\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Lyre\Model;
use Lyre\Billing\Support\BillingSupport;

class BillableUsage extends Model
{
    use HasFactory;

    protected $fillable = [
        'billable_item_id',
        'subscription_id',
        'user_id',
        'amount',
        'quantity',
        'recorded_at',
        'metadata',
    ];

    protected $casts = [
        'amount' => 'float',
        'quantity' => 'float',
        'recorded_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function billableItem()
    {
        return $this->belongsTo(BillableItem::class);
    }

    public function user()
    {
        return $this->belongsTo(BillingSupport::userModel());
    }

    public function subscription()
    {
        return $this->belongsTo(config('billing.models.subscription'));
    }
}
