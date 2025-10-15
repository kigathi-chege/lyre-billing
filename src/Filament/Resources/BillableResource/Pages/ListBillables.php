<?php

namespace Lyre\Billing\Filament\Resources\BillableResource\Pages;

use Lyre\Billing\Filament\Resources\BillableResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListBillables extends ListRecords
{
    protected static string $resource = BillableResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
