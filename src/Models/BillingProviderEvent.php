<?php

namespace Lyre\Billing\Models;

class BillingProviderEvent extends BillingRecord
{
    protected $hidden = ['payload'];

    protected $casts = ['payload' => 'encrypted:array', 'received_at' => 'datetime', 'completed_at' => 'datetime', 'lease_until' => 'datetime', 'livemode' => 'boolean'];
}
