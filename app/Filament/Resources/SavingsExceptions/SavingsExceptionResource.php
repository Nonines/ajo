<?php

namespace App\Filament\Resources\SavingsExceptions;

use App\Filament\Resources\SavingsExceptions\Pages\ManageSavingsExceptions;
use App\Models\SavingsException;
use App\Models\Staff;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Displays blocked savings evidence without exposing edit or delete actions.
 */
class SavingsExceptionResource extends Resource
{
    protected static ?string $model = SavingsException::class;

    /**
     * Restricts this view to eligible staff with exceptions.manage permission.
     */
    public static function canViewAny(): bool
    {
        $staff = Filament::auth()->user();

        return $staff instanceof Staff && $staff->is_active && $staff->hasRole('admin') && $staff->can('exceptions.manage');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    /**
     * Shows retained savings failures without allowing staff to erase their evidence.
     */
    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('savings_plan_id')->label('Plan'), TextColumn::make('operation'),
            TextColumn::make('message')->wrap(), TextColumn::make('created_at')])->recordActions([])->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageSavingsExceptions::route('/')];
    }
}
