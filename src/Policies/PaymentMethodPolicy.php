<?php

namespace Lyre\Billing\Policies;

use Lyre\Billing\Models\PaymentMethod;
use Lyre\Policy;

class PaymentMethodPolicy extends Policy
{
    public function __construct(PaymentMethod $model)
    {
        parent::__construct($model);
    }
}
