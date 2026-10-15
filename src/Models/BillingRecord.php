<?php

namespace Lyre\Billing\Models;

use Lyre\Model;

abstract class BillingRecord extends Model
{
    protected $guarded = ['id'];

    // Evidence is scoped by provider/account/mode and authorized admin endpoints.
    public static function bootBelongsToTenant() {}
}
