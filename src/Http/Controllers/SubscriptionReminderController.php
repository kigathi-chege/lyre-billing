<?php

namespace Lyre\Billing\Http\Controllers;

use Lyre\Billing\Models\SubscriptionReminder;
use Lyre\Billing\Contracts\SubscriptionReminderRepositoryInterface;
use Lyre\Controller;

class SubscriptionReminderController extends Controller
{
    public function __construct(
        SubscriptionReminderRepositoryInterface $modelRepository
    ) {
        $model = new SubscriptionReminder();
        $modelConfig = $model->generateConfig();
        parent::__construct($modelConfig, $modelRepository);
    }
}
