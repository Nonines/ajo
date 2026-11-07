<?php

namespace App\Support;

use DomainException;
use Spatie\Activitylog\Actions\CleanActivityLogAction;

class PreventAuditCleanup extends CleanActivityLogAction
{
    public function execute(int $maxAgeInDays, ?string $logName = null): int
    {
        throw new DomainException('Protected audit evidence requires verified maintenance archives.');
    }
}
