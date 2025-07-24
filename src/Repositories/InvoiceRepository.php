<?php

namespace Lyre\Billing\Repositories;

use Lyre\Repository;
use Lyre\Billing\Models\Invoice;
use Lyre\Billing\Contracts\InvoiceRepositoryInterface;

class InvoiceRepository extends Repository implements InvoiceRepositoryInterface
{
    protected $model;

    public function __construct(Invoice $model)
    {
        parent::__construct($model);
    }
}
