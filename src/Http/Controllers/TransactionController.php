<?php

namespace Lyre\Billing\Http\Controllers;

use Lyre\Billing\Models\Transaction;
use Lyre\Billing\Contacts\TransactionRepositoryInterface;
use Lyre\Controller;

class TransactionController extends Controller
{
    public function __construct(
        TransactionRepositoryInterface $modelRepository
    ) {
        $model = new Transaction();
        $modelConfig = $model->generateConfig();
        parent::__construct($modelConfig, $modelRepository);
    }
}
