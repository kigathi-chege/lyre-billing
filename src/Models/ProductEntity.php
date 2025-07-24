<?php

namespace Lyre\Billing\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Lyre\Model;

class ProductEntity extends Model
{
    use HasFactory;

    public function entity(): MorphTo
    {
        return $this->morphTo();
    }
}
