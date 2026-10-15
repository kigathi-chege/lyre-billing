<?php

namespace Lyre\Billing\Models;

class BillingEventAttempt extends BillingRecord
{
    protected $casts = ['started_at' => 'datetime', 'finished_at' => 'datetime'];
}
