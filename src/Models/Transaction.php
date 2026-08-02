<?php

namespace Lyre\Billing\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Lyre\Model;
use Lyre\Billing\Support\BillingSupport;
use Lyre\Scopes\OwnsScope;

class Transaction extends Model
{
    /**
     * Canonical statuses for the (non-enum, varchar) `status` column. Identity-mapped
     * so Lyre's get_status_code validates against this list instead of throwing
     * "Status config not found" when the status filter is applied.
     */
    const STATUSES = ['pending', 'completed', 'failed', 'cancelled'];

    public static function booted()
    {
        static::addGlobalScope(new OwnsScope);
    }

    use HasFactory;

    protected $casts = [
        'metadata' => 'array',
    ];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function user()
    {
        return $this->belongsTo(BillingSupport::userModel());
    }

    public function paymentMethod()
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function order()
    {
        if (class_exists(\Lyre\Commerce\Models\Order::class)) {
            return $this->belongsTo(\Lyre\Commerce\Models\Order::class, 'order_reference', 'reference');
        }
        return null;
    }
}
