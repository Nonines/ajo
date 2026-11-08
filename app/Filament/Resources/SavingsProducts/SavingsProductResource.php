<?php

namespace App\Filament\Resources\SavingsProducts;

use App\Filament\Resources\SavingsProducts\Pages\ManageSavingsProducts;
use App\Models\SavingsProduct;
use App\Models\Staff;
use App\Support\Auth\Approvals;
use App\Support\Savings\SavingsProducts;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

/**
 * Publishes new terms versions and routes existing-product rate changes through proposals.
 */
class SavingsProductResource extends Resource
{
    protected static ?string $model = SavingsProduct::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $recordTitleAttribute = 'name';

    /**
     * Reuses the same terms fields for product creation and publication.
     */
    public static function form(Schema $schema): Schema
    {
        return $schema->components(static::fields());
    }

    /** @return array<Component> */
    public static function fields(): array
    {
        return [
            TextInput::make('name')->required()->maxLength(255),
            Textarea::make('disclosure')->required()->maxLength(10000)->columnSpanFull()
                ->helperText('Explain the interest mode, lock, holding period and deductions. No plan fees. Net maturity proceeds repay recovery debt first.'),
            Toggle::make('available')->label('Available for new plans and renewals')->default(true),
            Select::make('interest')->options(['simple' => 'Simple interest', 'daily' => 'Daily compounding', '30_days' => 'Compounding every 30 days'])->default('simple')->required(),
            Select::make('lock')->options(['terminable' => 'Terminable after minimum holding', 'uncancellable' => 'Uncancellable until maturity',
                'until_maturity' => 'Existing lock until maturity'])->default('uncancellable')->required()->live(),
            TextInput::make('minimum_holding_days')->label('Minimum holding (whole days from accrual start)')->integer()->minValue(1)
                ->visible(fn (Get $get): bool => $get('lock') === 'terminable')->required(fn (Get $get): bool => $get('lock') === 'terminable'),
            TextInput::make('forfeiture_bp')->label('Early-exit interest forfeiture (basis points)')->integer()->minValue(0)->maxValue(10000)->default(0)->required(),
            TextInput::make('principal_penalty_bp')->label('Early-exit original-principal penalty (basis points)')->integer()->minValue(0)->maxValue(10000)->default(0)->required(),
            TextInput::make('minimum')->label('Minimum principal (kobo)')->required()->rules(['regex:/^[1-9][0-9]*$/D']),
            TextInput::make('maximum')->label('Maximum principal (kobo)')->required()->rules(['regex:/^[1-9][0-9]*$/D']),
            TextInput::make('withholding_bp')->label('Withholding (basis points)')->integer()->minValue(0)->maxValue(10000)->default(config('savings.withholding_bp'))->required(),
            Repeater::make('terms')->schema([
                TextInput::make('days')->integer()->minValue(30)->rules(['multiple_of:30'])->required()->distinct(),
                TextInput::make('annual_rate_bp')->label('Nominal annual rate (basis points)')->integer()->minValue(0)->required(),
            ])->minItems(1)->required()->columnSpanFull(),
        ];
    }

    /**
     * Separates ordinary version publication from rate changes requiring independent approval.
     */
    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')->searchable(), TextColumn::make('current_version')->label('Published version')])
            ->recordActions([EditAction::make()->label('Publish new version')
                ->fillForm(fn (SavingsProduct $record): array => $record->versions()->where('version', $record->current_version)->sole()->contract)
                ->using(fn (array $data, SavingsProduct $record): SavingsProduct => static::publish($data, $record)),
                Action::make('proposeRates')->label('Propose rate change')
                    ->schema([...static::fields(), Textarea::make('reason')->required()->maxLength(10000)])
                    ->fillForm(fn (SavingsProduct $record): array => $record->versions()->where('version', $record->current_version)->sole()->contract)
                    ->visible(fn (): bool => Filament::auth()->user()?->can('rates.make') ?? false)
                    ->action(function (SavingsProduct $record, array $data): void {
                        $staff = Filament::auth()->user();
                        abort_unless($staff instanceof Staff, 403);
                        $reason = $data['reason'];
                        unset($data['reason']);
                        app(Approvals::class)->propose($staff, 'product.rate', $record, ['data' => $data], $reason);
                    }),
            ]);
    }

    /**
     * Uses the publishing service and maps business-rule failures to the terms form.
     *
     * @param  array<string, mixed>  $data
     */
    public static function publish(array $data, ?SavingsProduct $product = null): SavingsProduct
    {
        $staff = Filament::auth()->user();
        abort_unless($staff instanceof Staff, 403);

        try {
            return app(SavingsProducts::class)->publish($staff, $data, $product);
        } catch (\DomainException $exception) {
            throw ValidationException::withMessages(['terms' => $exception->getMessage()]);
        }
    }

    public static function getPages(): array
    {
        return ['index' => ManageSavingsProducts::route('/')];
    }
}
