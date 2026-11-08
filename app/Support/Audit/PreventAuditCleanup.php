<?php

namespace App\Support\Audit;

use DomainException;
use Spatie\Activitylog\Actions\CleanActivityLogAction;

/**
 * Prevents generic activity-log cleanup from deleting evidence outside verified archival maintenance.
 */
class PreventAuditCleanup extends CleanActivityLogAction
{
    /**
     * Rejects generic cleanup so evidence removal must use verified archival maintenance.
     */
    public function execute(int $maxAgeInDays, ?string $logName = null): int
    {
        throw new DomainException('Protected audit evidence requires verified maintenance archives.');
    }
}
