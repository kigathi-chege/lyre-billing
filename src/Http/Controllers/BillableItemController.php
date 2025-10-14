<?php

namespace App\Http\Controllers;

use App\Models\BillableItem;
use App\Repositories\Interface\BillableItemRepositoryInterface;
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
