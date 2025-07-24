<?php

namespace Lyre\Billing\Http\Resources;

use Lyre\Billing\Models\SubscriptionReminder as SubscriptionReminderModel;
use Lyre\Resource;

class SubscriptionReminder extends Resource
{
    public function __construct(SubscriptionReminderModel $model)
    {
        parent::__construct($model);
    }
}
