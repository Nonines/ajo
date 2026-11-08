<?php

namespace App\Filament\Resources\SavingsExceptions\Pages;

use App\Filament\Resources\SavingsExceptions\SavingsExceptionResource;
use Filament\Resources\Pages\ManageRecords;

/**
 * Keeps retained savings failures visible without direct creation or mutation.
 */
class ManageSavingsExceptions extends ManageRecords
{
    protected static string $resource = SavingsExceptionResource::class;

    /**
     * Suppresses creation actions because failures are recorded by processing services.
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
