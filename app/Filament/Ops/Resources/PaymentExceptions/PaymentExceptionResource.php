<?php

namespace App\Filament\Ops\Resources\PaymentExceptions;

use App\Filament\Ops\Resources\PaymentExceptions\Pages\ManagePaymentExceptions;
use App\Jobs\ProcessPaymentWebhook;
use App\Jobs\VerifyDeposit;
use App\Jobs\VerifyWithdrawal;
use App\Models\PaymentException;
use App\Models\WebhookEvent;
use App\Support\Payments\PaymentInbox;
use Filament\Actions\Action;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Retries evidence verification rather than allowing staff to assign financial outcomes directly.
 */
class PaymentExceptionResource extends Resource
{
    protected static ?string $model = PaymentException::class;

    /**
     * Offers verification retries for unresolved evidence without assigning financial outcomes.
     */
    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('deposit.reference')->label('Payment')->searchable(),
            TextColumn::make('code')->badge(), TextColumn::make('message')->wrap(),
            TextColumn::make('response_due_at')->dateTime()->label('Provider deadline'),
            TextColumn::make('resolved_at')->dateTime(), TextColumn::make('created_at')->since(),
        ])->recordActions([
            Action::make('retry')->label('Retry verification')
                ->visible(fn (PaymentException $record): bool => $record->resolved_at === null)
                ->action(function (PaymentException $record, PaymentInbox $inbox): void {
                    Gate::authorize('update', $record);

                    if ($record->resource_type === 'withdrawal' && $record->resource_id !== null) {
                        VerifyWithdrawal::dispatch((int) $record->resource_id);
                    } elseif ($record->resource_type !== null && $record->resource_id !== null) {
                        $inbox->observe($record->resource_type, ['id' => $record->resource_id, 'staff_retry' => (string) Str::uuid()]);
                    }

                    if ($record->deposit_id !== null) {
                        VerifyDeposit::dispatch($record->deposit_id);
                    }

                    if ($record->webhook_event_id !== null) {
                        WebhookEvent::whereKey($record->webhook_event_id)->update(['processed_at' => null]);
                        ProcessPaymentWebhook::dispatch($record->webhook_event_id);
                    }
                }),
        ]);
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return ['index' => ManagePaymentExceptions::route('/')];
    }
}
