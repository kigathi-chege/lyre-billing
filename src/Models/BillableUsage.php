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
        'user_id',
        'amount',
        'recorded_at',
    ];

    protected $casts = [
        'amount' => 'float',
        'recorded_at' => 'datetime',
    ];

    public function billableItem()
    {
        return $this->belongsTo(BillableItem::class);
    }

    public function user()
    {
        return $this->belongsTo(BillingSupport::userModel());
    }
}
