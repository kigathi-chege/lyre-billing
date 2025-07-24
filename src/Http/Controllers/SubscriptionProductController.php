<?php

namespace Lyre\Billing\Http\Controllers;

use Lyre\Billing\Models\SubscriptionProduct;
use Lyre\Billing\Contracts\SubscriptionProductRepositoryInterface;
use Lyre\Controller;

class SubscriptionProductController extends Controller
{
    public function __construct(
        SubscriptionProductRepositoryInterface $modelRepository
    ) {
        $model = new SubscriptionProduct();
        $modelConfig = $model->generateConfig();
        parent::__construct($modelConfig, $modelRepository);
    }
}
