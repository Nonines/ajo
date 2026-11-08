<?php

namespace App\Filament\Resources\AuditEvents;

use App\Filament\Resources\AuditEvents\Pages\ManageAuditEvents;
use App\Models\AuditEvent;
use App\Models\Staff;
use App\Support\Audit\AuditTrail;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Provides a read-only audit view and records authorized access to the audit query.
 */
class AuditEventResource extends Resource
{
    protected static ?string $model = AuditEvent::class;

    /**
     * Restricts this view to eligible staff with audit.view permission.
     */
    public static function canViewAny(): bool
    {
        $staff = Filament::auth()->user();

        return $staff instanceof Staff && $staff->is_active && $staff->hasRole('admin') && $staff->can('audit.view');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * Records an authorized audit read before returning the resource query.
     */
    public static function getEloquentQuery(): Builder
    {
        $staff = Filament::auth()->user();

        if ($staff instanceof Staff && static::canViewAny()) {
            app(AuditTrail::class)->record('staff.audit_read', actor: $staff);
        }

        return parent::getEloquentQuery();
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
     * Shows sealed event evidence without exposing mutation actions.
     */
    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('id'), TextColumn::make('event')->searchable()->badge(), TextColumn::make('causer_type')->formatStateUsing(fn (?string $state): string => $state === null ? 'System' : class_basename($state)),
            TextColumn::make('causer_id'), TextColumn::make('subject_type')->formatStateUsing(fn (?string $state): string => $state === null ? '' : class_basename($state)),
            TextColumn::make('subject_id'), TextColumn::make('created_at')->dateTime(),
            TextColumn::make('evidence')->state(fn (AuditEvent $record): string => json_encode(['properties' => $record->properties, 'changes' => $record->attribute_changes], JSON_THROW_ON_ERROR))->wrap(),
            TextColumn::make('hash')->wrap()->toggleable(isToggledHiddenByDefault: true),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageAuditEvents::route('/')];
    }
}
