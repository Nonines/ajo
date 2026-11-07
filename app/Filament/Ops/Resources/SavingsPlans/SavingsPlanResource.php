<?php

namespace App\Filament\Ops\Resources\SavingsPlans;

use App\Filament\Ops\Resources\SavingsPlans\Pages\ManageSavingsPlans;
use App\Models\SavingsPlan;
use App\Models\Staff;
use App\Support\Approvals;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class SavingsPlanResource extends Resource
{
    protected static ?string $model = SavingsPlan::class;

    public static function canViewAny(): bool
    {
        $staff = Filament::auth()->user();

        return $staff instanceof Staff && $staff->is_active && $staff->can('customers.view');
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
        $actions = [];
        foreach (['hold' => 'active', 'release' => 'suspended'] as $action => $state) {
            $actions[] = Action::make($action)->label('Propose '.$action)->schema([Textarea::make('reason')->required()->maxLength(10000)])
                ->visible(fn (SavingsPlan $record): bool => (string) $record->state === $state
                    && (Filament::auth()->user()?->can('plans.'.$action) ?? false))
                ->action(function (SavingsPlan $record, array $data) use ($action): void {
                    $staff = Filament::auth()->user();
                    abort_unless($staff instanceof Staff, 403);
                    app(Approvals::class)->propose($staff, 'plan.'.$action, $record, [], $data['reason']);
                });
        }

        return $table->columns([TextColumn::make('id'), TextColumn::make('user.email')->searchable(),
            TextColumn::make('state')->formatStateUsing(fn ($state): string => (string) $state)->badge(),
            TextColumn::make('contract.principal')->label('Principal (kobo)'), TextColumn::make('matures_at')->dateTime(),
        ])->recordActions($actions);
    }

    public static function getPages(): array
    {
        return ['index' => ManageSavingsPlans::route('/')];
    }
}
