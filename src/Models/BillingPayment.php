<?php

namespace Lyre\Billing\Models;

class BillingPayment extends BillingRecord
{
    protected $casts = ['snapshot' => 'encrypted:array', 'livemode' => 'boolean'];
}
