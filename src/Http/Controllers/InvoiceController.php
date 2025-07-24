<?php

namespace Lyre\Billing\Http\Controllers;

use Lyre\Billing\Models\Invoice;
use Lyre\Billing\Contracts\InvoiceRepositoryInterface;
use Lyre\Controller;

class InvoiceController extends Controller
{
    public function __construct(
        InvoiceRepositoryInterface $modelRepository
    ) {
        $model = new Invoice();
        $modelConfig = $model->generateConfig();
        parent::__construct($modelConfig, $modelRepository);
    }
}
