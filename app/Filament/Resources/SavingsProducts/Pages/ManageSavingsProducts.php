<?php

namespace App\Filament\Resources\SavingsProducts\Pages;

use App\Filament\Resources\SavingsProducts\SavingsProductResource;
use App\Models\SavingsProduct;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageSavingsProducts extends ManageRecords
{
    protected static string $resource = SavingsProductResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->using(fn (array $data): SavingsProduct => SavingsProductResource::publish($data))];
    }
}
