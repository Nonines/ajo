<?php

namespace App\Filament\Ops\Resources\Users\Pages;

use App\Filament\Ops\Resources\Users\UserResource;
use Filament\Resources\Pages\ManageRecords;

class ManageUsers extends ManageRecords
{
    protected static string $resource = UserResource::class;
}
