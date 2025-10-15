<?php

namespace Lyre\Billing\Repositories;

use Lyre\Repository;
use Lyre\Billing\Models\Transaction;
use Lyre\Billing\Contracts\TransactionRepositoryInterface;

class TransactionRepository extends Repository implements TransactionRepositoryInterface
{
    protected $model;

    public function __construct(Transaction $model)
    {
        parent::__construct($model);
    }
}
