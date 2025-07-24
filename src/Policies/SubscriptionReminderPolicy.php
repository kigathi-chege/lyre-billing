<?php

namespace Lyre\Billing\Policies;

use Lyre\Billing\Models\SubscriptionReminder;
use Lyre\Policy;

class SubscriptionReminderPolicy extends Policy
{
    public function __construct(SubscriptionReminder $model)
    {
        parent::__construct($model);
    }
}
