<?php

namespace Lyre\Billing\Policies;

use Lyre\Billing\Models\Transaction;
use Lyre\Policy;

class TransactionPolicy extends Policy
{
    public function __construct(Transaction $model)
    {
        parent::__construct($model);
    }
}
