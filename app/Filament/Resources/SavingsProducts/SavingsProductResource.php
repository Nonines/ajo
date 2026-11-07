<?php

namespace App\Filament\Resources\SavingsProducts;

use App\Filament\Resources\SavingsProducts\Pages\ManageSavingsProducts;
use App\Models\SavingsProduct;
use App\Models\Staff;
use App\Support\SavingsProducts;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SavingsProductResource extends Resource
{
    protected static ?string $model = SavingsProduct::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            Textarea::make('disclosure')->required()->maxLength(10000)->columnSpanFull()
                ->helperText('Fixed-term simple interest, Actual/365, locked until maturity. No plan fees. Net maturity proceeds repay recovery debt first.'),
            TextInput::make('minimum')->label('Minimum principal (kobo)')->required()->rules(['regex:/^[1-9][0-9]*$/D']),
            TextInput::make('maximum')->label('Maximum principal (kobo)')->required()->rules(['regex:/^[1-9][0-9]*$/D']),
            TextInput::make('withholding_bp')->label('Withholding (basis points)')->integer()->minValue(0)->maxValue(10000)->default(config('savings.withholding_bp'))->required(),
            Repeater::make('terms')->schema([
                TextInput::make('days')->integer()->minValue(30)->rules(['multiple_of:30'])->required()->distinct(),
                TextInput::make('annual_rate_bp')->label('Nominal annual rate (basis points)')->integer()->minValue(0)->required(),
            ])->minItems(1)->required()->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')->searchable(), TextColumn::make('current_version')->label('Published version')])
            ->recordActions([EditAction::make()->label('Publish new version')
                ->fillForm(fn (SavingsProduct $record): array => $record->versions()->where('version', $record->current_version)->sole()->contract)
                ->using(fn (array $data, SavingsProduct $record): SavingsProduct => static::publish($data, $record))]);
    }

    /** @param array<string, mixed> $data */
    public static function publish(array $data, ?SavingsProduct $product = null): SavingsProduct
    {
        $staff = Filament::auth()->user();
        abort_unless($staff instanceof Staff, 403);

        return app(SavingsProducts::class)->publish($staff, $data, $product);
    }

    public static function getPages(): array
    {
        return ['index' => ManageSavingsProducts::route('/')];
    }
}
