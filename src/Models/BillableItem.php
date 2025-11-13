<?php

namespace Lyre\Billing\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Lyre\Model;

class BillableItem extends Model
{
    use HasFactory;

    protected $casts = [
        'metadata' => 'array',
    ];

    public function billable()
    {
        return $this->belongsTo(Billable::class);
    }

    public function item(): MorphTo
    {
        return $this->morphTo();
    }

    public function billableUsages()
    {
        return $this->hasMany(BillableUsage::class);
    }
}
