<?php

namespace App\Filament\Resources\PaymentSimulations\Pages;

use App\Filament\Resources\PaymentSimulations\PaymentSimulationResource;
use App\Models\Staff;
use App\Models\User;
use App\Support\Money\Money;
use App\Support\Payments\PaymentSimulator;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ManageRecords;

/**
 * Creates scenarios that exercise the application's normal financial checks.
 */
class ManagePaymentSimulations extends ManageRecords
{
    protected static string $resource = PaymentSimulationResource::class;

    /**
     * Creates explicitly simulated transactions for active, verified customers.
     */
    protected function getHeaderActions(): array
    {
        return [Action::make('simulate')->label('Create explicitly simulated transaction')->schema([
            Select::make('user_id')->label('Customer')->options(fn (): array => User::where('is_active', true)->whereNotNull('email_verified_at')->pluck('email', 'id')->all())->required()->searchable(),
            Select::make('kind')->options(['deposit' => 'Deposit', 'withdrawal' => 'Withdrawal'])->required(),
            TextInput::make('amount')->label('Amount (kobo)')->required()->rules(['regex:/^[1-9][0-9]*$/D']),
        ])->action(function (array $data): void {
            $staff = Filament::auth()->user();
            abort_unless($staff instanceof Staff, 403);
            app(PaymentSimulator::class)->create($staff, User::whereKey($data['user_id'])->sole(), $data['kind'], new Money($data['amount']));
        })];
    }
}
