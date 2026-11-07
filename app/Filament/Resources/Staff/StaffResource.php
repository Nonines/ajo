<?php

namespace App\Filament\Resources\Staff;

use App\Filament\Resources\Staff\Pages\ManageStaff;
use App\Models\Staff;
use App\Support\SecurityAudit;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Password;

class StaffResource extends Resource
{
    protected static ?string $model = Staff::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('email')->email()->required()->maxLength(255)->unique(ignoreRecord: true),
                TextInput::make('password')->password()->rules([Password::defaults()])
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->saved(fn (?string $state): bool => filled($state)),
                Select::make('roles')->relationship('roles', 'name',
                    modifyQueryUsing: fn (Builder $query): Builder => $query->where('guard_name', 'staff')
                        ->whereIn('name', ['admin', 'manager']))
                    ->multiple()->minItems(1)->required()->preload(),
                Select::make('permissions')->relationship('permissions', 'name',
                    modifyQueryUsing: fn (Builder $query): Builder => $query->where('guard_name', 'staff'))
                    ->multiple()->preload()->helperText('Additional permissions. Role permissions still apply.'),
                Toggle::make('is_active')->default(true)->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('email')->searchable(),
                TextColumn::make('roles.name')->badge(),
                TextColumn::make('is_active')->formatStateUsing(fn (bool $state): string => $state ? 'Active' : 'Inactive'),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make()->databaseTransaction()
                    ->after(fn (Staff $record) => app(SecurityAudit::class)->staffPermissions($record)),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageStaff::route('/'),
        ];
    }
}
