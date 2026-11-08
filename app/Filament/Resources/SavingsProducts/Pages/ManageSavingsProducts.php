<?php

namespace App\Filament\Resources\SavingsProducts\Pages;

use App\Filament\Resources\SavingsProducts\SavingsProductResource;
use App\Models\SavingsProduct;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

/**
 * Creates product terms through the versioned publication service.
 */
class ManageSavingsProducts extends ManageRecords
{
    protected static string $resource = SavingsProductResource::class;

    /**
     * Creates products through versioned publication rather than direct model writes.
     */
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->using(fn (array $data): SavingsProduct => SavingsProductResource::publish($data))];
    }
}
