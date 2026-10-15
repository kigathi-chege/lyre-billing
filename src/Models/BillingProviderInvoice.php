<?php

namespace Lyre\Billing\Models;

class BillingProviderInvoice extends BillingRecord
{
    protected $casts = ['snapshot' => 'encrypted:array', 'period_start' => 'datetime', 'period_end' => 'datetime', 'livemode' => 'boolean'];
}
