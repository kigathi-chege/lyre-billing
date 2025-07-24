<?php

namespace Lyre\Billing\Http\Controllers;

use Lyre\Billing\Models\ProductEntity;
use Lyre\Billing\Contracts\ProductEntityRepositoryInterface;
use Lyre\Controller;

class ProductEntityController extends Controller
{
    public function __construct(
        ProductEntityRepositoryInterface $modelRepository
    ) {
        $model = new ProductEntity();
        $modelConfig = $model->generateConfig();
        parent::__construct($modelConfig, $modelRepository);
    }
}
