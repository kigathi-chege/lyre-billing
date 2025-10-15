<?php

namespace Lyre\Billing\Filament\Resources\PaymentMethodResource\Pages;

use Lyre\Billing\Filament\Resources\PaymentMethodResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePaymentMethod extends CreateRecord
{
    protected static string $resource = PaymentMethodResource::class;
}
