<?php

namespace Lyre\Billing\Models;

class BillingStateChange extends BillingRecord
{
    protected $casts = ['before' => 'array', 'after' => 'array', 'mail_sent_at' => 'datetime', 'delivered_at' => 'datetime'];
}
