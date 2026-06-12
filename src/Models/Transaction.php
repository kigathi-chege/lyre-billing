<?php

namespace Lyre\Billing\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Lyre\Model;
use Lyre\Billing\Support\BillingSupport;
use Lyre\Scopes\OwnsScope;

class Transaction extends Model
{
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
