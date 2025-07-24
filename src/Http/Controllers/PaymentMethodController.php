<?php

namespace Lyre\Billing\Http\Controllers;

use Lyre\Billing\Models\PaymentMethod;
use Lyre\Billing\Contracts\PaymentMethodRepositoryInterface;
use Lyre\Controller;

class PaymentMethodController extends Controller
{
    public function __construct(
        PaymentMethodRepositoryInterface $modelRepository
    ) {
        $model = new PaymentMethod();
        $modelConfig = $model->generateConfig();
        parent::__construct($modelConfig, $modelRepository);
    }
}
