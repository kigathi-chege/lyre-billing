<?php

namespace Lyre\Billing\Http\Controllers;

use Lyre\Billing\Models\Product;
use Lyre\Billing\Contracts\ProductRepositoryInterface;
use Lyre\Controller;

class ProductController extends Controller
{
    public function __construct(
        ProductRepositoryInterface $modelRepository
    ) {
        $model = new Product();
        $modelConfig = $model->generateConfig();
        parent::__construct($modelConfig, $modelRepository);
    }
}
