<?php

namespace App\Filament\Ops\Resources\Users;

use App\Filament\Ops\Resources\Users\Pages\ManageUsers;
use App\Models\LedgerAccount;
use App\Models\Staff;
use App\Models\User;
use App\Support\Approvals;
use App\Support\Ledger;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Displays customer balances and proposes recovery or journal changes for independent approval.
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationLabel = 'Customers and recovery';

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
        foreach (['release', 'write-off'] as $action) {
            $fields = [Textarea::make('reason')->required()->maxLength(10000)];
            if ($action === 'write-off') {
                $fields[] = TextInput::make('amount')->label('Write-off (kobo)')->required()->rules(['regex:/^[1-9][0-9]*$/D']);
            }
            $actions[] = Action::make($action)->label('Propose recovery '.$action)->schema($fields)
                ->visible(fn (): bool => Filament::auth()->user()?->can('recovery.'.$action) ?? false)
                ->action(function (User $record, array $data) use ($action): void {
                    $staff = Filament::auth()->user();
                    abort_unless($staff instanceof Staff, 403);
                    app(Approvals::class)->propose($staff, 'recovery.'.$action, $record,
                        $action === 'write-off' ? ['amount' => $data['amount']] : [], $data['reason']);
                });
        }
        $actions[] = Action::make('adjustment')->label('Propose ledger adjustment')->schema([
            Textarea::make('reason')->required()->maxLength(10000),
            TextInput::make('effective_date')->type('date')->required(),
            Repeater::make('entries')->minItems(2)->schema([
                Select::make('account_id')->label('Account')->options(fn (User $record): array => LedgerAccount::whereNull('user_id')
                    ->orWhere('code', 'customer:'.$record->id.':wallet')->pluck('code', 'id')->all())->required()->distinct(),
                TextInput::make('amount')->label('Signed kobo: debit positive, credit negative')->required()->rules(['regex:/^-?[0-9]+$/D']),
            ]),
        ])->visible(fn (): bool => Filament::auth()->user()?->can('adjustments.make') ?? false)
            ->action(function (User $record, array $data): void {
                $staff = Filament::auth()->user();
                abort_unless($staff instanceof Staff, 403);
                app(Approvals::class)->propose($staff, 'adjustment', $record,
                    ['entries' => $data['entries'], 'effective_date' => $data['effective_date']], $data['reason']);
            });

        return $table->columns([TextColumn::make('id'), TextColumn::make('name')->searchable(), TextColumn::make('email')->searchable(),
            TextColumn::make('wallet')->label('Wallet (kobo)')->state(fn (User $record): string => self::balance($record, 'wallet', true)),
            TextColumn::make('debt')->label('Recovery debt (kobo)')->state(fn (User $record): string => self::balance($record, 'reversal_receivable')),
            TextColumn::make('is_active')->formatStateUsing(fn (bool $state): string => $state ? 'Active' : 'Inactive'),
        ])->recordActions($actions);
    }

    /**
     * Displays ledger totals and optionally normalizes a credit-negative wallet liability.
     */
    private static function balance(User $user, string $suffix, bool $normalize = false): string
    {
        $account = LedgerAccount::where('code', 'customer:'.$user->id.':'.$suffix)->first();
        if ($account === null) {
            return '0';
        }
        $money = app(Ledger::class)->balance($account);

        return (string) ($normalize ? $money->negate()->kobo : $money->kobo);
    }

    public static function getPages(): array
    {
        return ['index' => ManageUsers::route('/')];
    }
}
