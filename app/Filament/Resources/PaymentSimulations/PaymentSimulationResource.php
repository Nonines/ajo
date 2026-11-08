<?php

namespace App\Filament\Resources\PaymentSimulations;

use App\Filament\Resources\PaymentSimulations\Pages\ManagePaymentSimulations;
use App\Models\PaymentSimulation;
use App\Models\Staff;
use App\Support\PaymentSimulator;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Exposes outcome scenarios only to permitted administrators in enabled local or test environments.
 */
class PaymentSimulationResource extends Resource
{
    protected static ?string $model = PaymentSimulation::class;

    public static function canViewAny(): bool
    {
        $staff = Filament::auth()->user();

        return $staff instanceof Staff && $staff->is_active && $staff->hasRole('admin') && $staff->can('simulator.use')
            && app()->environment(['local', 'testing']) && config('withdrawals.simulation_enabled') === true;
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

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([TextColumn::make('reference')->searchable(),
            TextColumn::make('subject_type')->formatStateUsing(fn (string $state): string => class_basename($state)),
            TextColumn::make('subject_id'), TextColumn::make('evidence.status')->label('Adapter outcome'),
        ])->recordActions([
            Action::make('observe')->label('Simulate outcome')->schema([Select::make('scenario')->options([
                'success' => 'Success', 'failure' => 'Failure', 'reversal' => 'Reversal', 'missing' => 'Missing webhook',
                'duplicate' => 'Duplicate webhook', 'reordered' => 'Reordered webhooks', 'conflicting' => 'Conflicting amount',
            ])->required()])->action(function (PaymentSimulation $record, array $data): void {
                $staff = Filament::auth()->user();
                abort_unless($staff instanceof Staff, 403);
                app(PaymentSimulator::class)->observe($staff, $record, $data['scenario']);
            }),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManagePaymentSimulations::route('/')];
    }
}
