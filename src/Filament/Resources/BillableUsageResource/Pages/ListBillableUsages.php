<?php

namespace Lyre\Billing\Filament\Resources\BillableUsageResource\Pages;

use Lyre\Billing\Filament\Resources\BillableUsageResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListBillableUsages extends ListRecords
{
    protected static string $resource = BillableUsageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Actions\CreateAction::make(),
        ];
    }
}
