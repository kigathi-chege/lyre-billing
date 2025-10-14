<?php

namespace Lyre\Billing\Http\Resources;

use Lyre\Billing\Models\Transaction as TransactionModel;
use Lyre\Resource;

class Transaction extends Resource
{
    public function __construct(TransactionModel $model)
    {
        parent::__construct($model);
    }
}
