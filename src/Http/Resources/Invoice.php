<?php

namespace Lyre\Billing\Http\Resources;

use Lyre\Billing\Models\Invoice as InvoiceModel;
use Lyre\Resource;

class Invoice extends Resource
{
    public function __construct(InvoiceModel $model)
    {
        parent::__construct($model);
    }
}
