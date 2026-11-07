<?php

namespace App\Filament\Ops\Resources\ApprovalRequests\Pages;

use App\Filament\Ops\Resources\ApprovalRequests\ApprovalRequestResource;
use Filament\Resources\Pages\ManageRecords;

class ManageApprovalRequests extends ManageRecords
{
    protected static string $resource = ApprovalRequestResource::class;
}
