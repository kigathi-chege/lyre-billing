<?php

namespace Lyre\Billing\Policies;

use Lyre\Billing\Models\Invoice;
use Lyre\Policy;

class InvoicePolicy extends Policy
{
    public function __construct(Invoice $model)
    {
        parent::__construct($model);
    }
}
