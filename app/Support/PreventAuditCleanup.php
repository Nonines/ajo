<?php

namespace App\Support;

use DomainException;
use Spatie\Activitylog\Actions\CleanActivityLogAction;

/**
 * Prevents generic activity-log cleanup from deleting evidence outside verified archival maintenance.
 */
class PreventAuditCleanup extends CleanActivityLogAction
{
    public function execute(int $maxAgeInDays, ?string $logName = null): int
    {
        throw new DomainException('Protected audit evidence requires verified maintenance archives.');
    }
}
