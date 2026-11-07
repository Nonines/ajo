<?php

namespace App\Filament\Resources\SavingsExceptions\Pages;

use App\Filament\Resources\SavingsExceptions\SavingsExceptionResource;
use Filament\Resources\Pages\ManageRecords;

class ManageSavingsExceptions extends ManageRecords
{
    protected static string $resource = SavingsExceptionResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
