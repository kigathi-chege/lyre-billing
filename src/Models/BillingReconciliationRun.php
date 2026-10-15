<?php

namespace Lyre\Billing\Models;

class BillingReconciliationRun extends BillingRecord
{
    protected $casts = ['summary' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
}
