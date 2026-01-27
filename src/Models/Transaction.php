<?php

namespace Lyre\Billing\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Lyre\Model;

class Transaction extends Model
{
    use HasFactory;

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
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
