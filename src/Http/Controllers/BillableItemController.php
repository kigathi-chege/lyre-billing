<?php

namespace Lyre\Billing\Http\Controllers;

use Lyre\Billing\Contracts\BillableItemRepositoryInterface;
use Lyre\Billing\Models\BillableItem;
use Lyre\Controller;

class BillableItemController extends Controller
{
    public function __construct(
        BillableItemRepositoryInterface $modelRepository
    ) {
        $model = new BillableItem();
        $modelConfig = $model->generateConfig();
        parent::__construct($modelConfig, $modelRepository);
    }
}
