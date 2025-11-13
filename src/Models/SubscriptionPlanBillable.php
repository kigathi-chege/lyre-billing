<?php

namespace Lyre\Billing\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Lyre\Model;

class SubscriptionPlanBillable extends Model
{
    use HasFactory;

    protected $fillable = [
        'subscription_plan_id',
        'billable_id',
        'usage_limit',
        'unit_price',
        'order',
    ];

    protected $casts = [
        'usage_limit' => 'integer',
        'unit_price' => 'decimal:2',
        'order' => 'integer',
    ];

    /**
     * Get the table associated with the model.
     *
     * @return string
     */
    public function getTable()
    {
        return config('lyre.table_prefix') . 'subscription_plan_billables';
    }

    public function subscriptionPlan()
    {
        return $this->belongsTo(SubscriptionPlan::class);
    }

    public function billable()
    {
        return $this->belongsTo(Billable::class);
    }
}

