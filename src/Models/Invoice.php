<?php

namespace Lyre\Billing\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Lyre\Model;

class Invoice extends Model
{
    use HasFactory;

    protected $casts = [
        'amount' => 'decimal:2',
        'amount_paid' => 'decimal:6',
        'due_date' => 'datetime',
    ];

    public function subscription()
    {
        return $this->belongsTo(Subscription::class);
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }
}
