<?php

namespace Lyre\Billing\Filament\Resources\BillableUsageResource\Pages;

use Lyre\Billing\Filament\Resources\BillableUsageResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditBillableUsage extends EditRecord
{
    protected static string $resource = BillableUsageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}

