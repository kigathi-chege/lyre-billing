<?php

namespace Lyre\Billing\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Lyre\Model;

class Invoice extends Model
{
    use HasFactory;

    public function subscription()
    {
        return $this->belongsTo(Subscription::class);
    }
}
