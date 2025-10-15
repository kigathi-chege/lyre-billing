<?php

namespace Lyre\Billing\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Lyre\Model;

class BillableItem extends Model
{
    use HasFactory;

    public function billable()
    {
        return $this->belongsTo(Billable::class);
    }

    public function item(): MorphTo
    {
        return $this->morphTo();
    }
}
