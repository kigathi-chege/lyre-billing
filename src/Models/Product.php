<?php

namespace Lyre\Billing\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Lyre\Facet\Concerns\HasFacet;
use Lyre\Model;

use App\Models\User;

class Product extends Model
{
    use HasFactory, HasFacet;

    protected $guarded = ['id'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function productEntities()
    {
        return $this->hasMany(ProductEntity::class);
    }
}
