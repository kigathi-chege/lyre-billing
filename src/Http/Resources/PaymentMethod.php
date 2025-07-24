<?php

namespace Lyre\Billing\Http\Resources;

use Lyre\Billing\Models\PaymentMethod as PaymentMethodModel;
use Lyre\Resource;

class PaymentMethod extends Resource
{
    public function __construct(PaymentMethodModel $model)
    {
        parent::__construct($model);
    }
}
