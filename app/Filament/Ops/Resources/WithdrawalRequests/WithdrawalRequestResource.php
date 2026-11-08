<?php

namespace App\Filament\Ops\Resources\WithdrawalRequests;

use App\Filament\Ops\Resources\WithdrawalRequests\Pages\ManageWithdrawalRequests;
use App\Models\Staff;
use App\Models\WithdrawalRequest;
use App\Support\Auth\Approvals;
use App\Support\Withdrawals\Withdrawals;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Routes single-review and maker-checker actions through the withdrawal approval services.
 */
class WithdrawalRequestResource extends Resource
{
    protected static ?string $model = WithdrawalRequest::class;

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
     * Selects single-review or maker-checker actions from the snapshotted reservation.
     */
    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('reference')->searchable()->wrap(), TextColumn::make('user.email')->label('Customer')->searchable(),
            TextColumn::make('principal')->label('Principal (kobo)'), TextColumn::make('reserved_fee')->label('Reserved fee (kobo)'),
            TextColumn::make('destination_display')->state(fn (WithdrawalRequest $record): string => $record->destination['bank_name'].' / ******'.substr($record->destination['account_number'], -4)),
            TextColumn::make('state')->formatStateUsing(fn ($state): string => (string) $state)->badge(),
            TextColumn::make('expires_at')->dateTime()->label('Review deadline'),
            TextColumn::make('provider_status')->badge(),
        ])->recordActions([
            Action::make('approve')->label('Approve single review')->schema([Textarea::make('reason')->required()->maxLength(10000)])
                ->visible(fn (WithdrawalRequest $record): bool => in_array((string) $record->state, ['requested', 'under_review'], true)
                    && ! app(Withdrawals::class)->requiresChecker($record) && (Filament::auth()->user()?->can('withdrawals.review') ?? false))
                ->action(function (WithdrawalRequest $record, array $data): void {
                    $staff = Filament::auth()->user();
                    abort_unless($staff instanceof Staff, 403);
                    app(Withdrawals::class)->review($staff, $record, true, $data['reason']);
                }),
            Action::make('make')->label('Propose for checker')->schema([Textarea::make('reason')->required()->maxLength(10000)])
                ->visible(fn (WithdrawalRequest $record): bool => (string) $record->state === 'requested'
                    && app(Withdrawals::class)->requiresChecker($record) && (Filament::auth()->user()?->can('withdrawals.make') ?? false))
                ->action(function (WithdrawalRequest $record, array $data): void {
                    $staff = Filament::auth()->user();
                    abort_unless($staff instanceof Staff, 403);
                    app(Approvals::class)->propose($staff, 'withdrawal', $record, [], $data['reason']);
                }),
            Action::make('reject')->schema([Textarea::make('reason')->required()->maxLength(10000)])
                ->visible(fn (WithdrawalRequest $record): bool => in_array((string) $record->state, ['requested', 'under_review'], true)
                    && (Filament::auth()->user()?->can('withdrawals.review') ?? false))
                ->action(function (WithdrawalRequest $record, array $data): void {
                    $staff = Filament::auth()->user();
                    abort_unless($staff instanceof Staff, 403);
                    app(Withdrawals::class)->review($staff, $record, false, $data['reason']);
                }),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageWithdrawalRequests::route('/')];
    }
}
