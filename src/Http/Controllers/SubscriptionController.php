<?php

namespace Lyre\Billing\Http\Controllers;

use Lyre\Billing\Models\Subscription;
use Lyre\Billing\Contracts\SubscriptionRepositoryInterface;
use Lyre\Controller;

class SubscriptionController extends Controller
{
    public function __construct(
        SubscriptionRepositoryInterface $modelRepository
    ) {
        $model = new Subscription();
        $modelConfig = $model->generateConfig();
        parent::__construct($modelConfig, $modelRepository);
    }
}
