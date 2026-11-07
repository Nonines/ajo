<?php

namespace App\Filament\Ops\Resources\WithdrawalRequests\Pages;

use App\Filament\Ops\Resources\WithdrawalRequests\WithdrawalRequestResource;
use Filament\Resources\Pages\ManageRecords;

class ManageWithdrawalRequests extends ManageRecords
{
    protected static string $resource = WithdrawalRequestResource::class;
}
