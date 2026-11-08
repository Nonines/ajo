<?php

namespace App\Filament\Resources\Staff\Pages;

use App\Filament\Resources\Staff\StaffResource;
use App\Models\Staff;
use App\Support\Audit\SecurityAudit;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

/**
 * Creates staff through audited transactional panel actions.
 */
class ManageStaff extends ManageRecords
{
    protected static string $resource = StaffResource::class;

    /**
     * Creates staff transactionally and audits their assigned access permissions.
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->databaseTransaction()
                ->after(fn (Staff $record) => app(SecurityAudit::class)->staffPermissions($record)),
        ];
    }
}
