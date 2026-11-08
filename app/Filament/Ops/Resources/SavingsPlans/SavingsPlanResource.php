<?php

namespace App\Filament\Ops\Resources\SavingsPlans;

use App\Filament\Ops\Resources\SavingsPlans\Pages\ManageSavingsPlans;
use App\Models\SavingsPlan;
use App\Models\Staff;
use App\Support\Auth\Approvals;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Creates staff proposals for plan holds, releases, waivers, and administrative closure.
 */
class SavingsPlanResource extends Resource
{
    protected static ?string $model = SavingsPlan::class;

    /**
     * Restricts this view to eligible staff with customers.view permission.
     */
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

    /**
     * Builds proposal actions so staff cannot directly rewrite plan state or settlement terms.
     */
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

        // Keep waiver caps separate from administrative correction evidence.
        $actions[] = Action::make('waive')->label('Propose penalty waiver')
            ->schema([TextInput::make('interest_cap')->label('Interest-forfeiture waiver cap (kobo)')->default('0')->required()->rules(['regex:/^(0|[1-9][0-9]*)$/D']),
                TextInput::make('principal_cap')->label('Principal-penalty waiver cap (kobo)')->default('0')->required()->rules(['regex:/^(0|[1-9][0-9]*)$/D']),
                Textarea::make('reason')->required()->maxLength(10000)])
            ->visible(fn (SavingsPlan $record): bool => (string) $record->state === 'active' && $record->contract['lock'] === 'terminable'
                && (Filament::auth()->user()?->can('waivers.make') ?? false))
            ->action(fn (SavingsPlan $record, array $data) => self::propose($record, 'plan.waive', $data));
        $actions[] = Action::make('close')->label('Propose administrative closure')
            ->schema([Select::make('correction')->options(['erroneous_creation' => 'Erroneous creation', 'erroneous_funding' => 'Erroneous funding', 'other' => 'Other administrative closure'])->required(),
                Textarea::make('evidence')->label('Correction evidence')->required()->maxLength(10000),
                Textarea::make('reason')->required()->maxLength(10000)])
            ->visible(fn (SavingsPlan $record): bool => in_array((string) $record->state, ['active', 'matured'], true)
                && (Filament::auth()->user()?->can('plans.close') ?? false))
            ->action(fn (SavingsPlan $record, array $data) => self::propose($record, 'plan.close', $data));

        return $table->columns([TextColumn::make('id'), TextColumn::make('user.email')->searchable(),
            TextColumn::make('state')->formatStateUsing(fn ($state): string => (string) $state)->badge(),
            TextColumn::make('contract.principal')->label('Principal (kobo)'), TextColumn::make('matures_at')->dateTime(),
        ])->recordActions($actions);
    }

    /**
     * Separates the staff reason from the action payload and exposes proposal validation errors.
     *
     * @param  array<string, mixed>  $data
     */
    private static function propose(SavingsPlan $record, string $action, array $data): void
    {
        $staff = Filament::auth()->user();
        abort_unless($staff instanceof Staff, 403);
        $reason = $data['reason'];
        unset($data['reason']);

        try {
            app(Approvals::class)->propose($staff, $action, $record, $data, $reason);
        } catch (\DomainException $exception) {
            throw ValidationException::withMessages(['reason' => $exception->getMessage()]);
        }
    }

    public static function getPages(): array
    {
        return ['index' => ManageSavingsPlans::route('/')];
    }
}
