<?php

namespace Lyre\Billing\Filament\Resources\PaymentMethodResource\Pages;

use Lyre\Billing\Filament\Actions\TestPayment;
use Lyre\Billing\Filament\Resources\PaymentMethodResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPaymentMethod extends EditRecord
{
    protected static string $resource = PaymentMethodResource::class;

    protected function getHeaderActions(): array
    {
        return [
            TestPayment::makePageAction(),
            Actions\DeleteAction::make(),
        ];
    }
}
