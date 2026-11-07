<?php

namespace App\Filament\Resources\AuditEvents\Pages;

use App\Filament\Resources\AuditEvents\AuditEventResource;
use Filament\Resources\Pages\ManageRecords;

class ManageAuditEvents extends ManageRecords
{
    protected static string $resource = AuditEventResource::class;
}
