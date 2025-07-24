<?php

namespace Lyre\Billing\Repositories;

use Lyre\Repository;
use Lyre\Billing\Models\SubscriptionReminder;
use Lyre\Billing\Contracts\SubscriptionReminderRepositoryInterface;

class SubscriptionReminderRepository extends Repository implements SubscriptionReminderRepositoryInterface
{
    protected $model;

    public function __construct(SubscriptionReminder $model)
    {
        parent::__construct($model);
    }
}
