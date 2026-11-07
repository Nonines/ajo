<?php

namespace App\Filament\Ops\Resources\ApprovalRequests;

use App\Filament\Ops\Resources\ApprovalRequests\Pages\ManageApprovalRequests;
use App\Models\ApprovalRequest;
use App\Models\Staff;
use App\Support\Approvals;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ApprovalRequestResource extends Resource
{
    protected static ?string $model = ApprovalRequest::class;

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
        foreach (['approve' => true, 'reject' => false] as $name => $accept) {
            $actions[] = Action::make($name)->schema([Textarea::make('reason')->required()->maxLength(10000)])
                ->visible(fn (ApprovalRequest $record): bool => $record->state === 'pending' && $record->requested_by !== Filament::auth()->id())
                ->action(function (ApprovalRequest $record, array $data) use ($accept): void {
                    $staff = Filament::auth()->user();
                    abort_unless($staff instanceof Staff, 403);
                    app(Approvals::class)->decide($staff, $record, $accept, $data['reason']);
                });
        }

        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('id'), TextColumn::make('action')->badge(), TextColumn::make('subject_type')->formatStateUsing(fn (string $state): string => class_basename($state)),
            TextColumn::make('subject_id'), TextColumn::make('requested_by')->label('Maker'), TextColumn::make('reason')->wrap(),
            TextColumn::make('proposal')->state(fn (ApprovalRequest $record): string => json_encode($record->payload, JSON_THROW_ON_ERROR))->wrap(),
            TextColumn::make('state')->badge(), TextColumn::make('expires_at')->dateTime(), TextColumn::make('decision_reason')->wrap(),
        ])->recordActions($actions);
    }

    public static function getPages(): array
    {
        return ['index' => ManageApprovalRequests::route('/')];
    }
}
