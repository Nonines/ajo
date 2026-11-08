<?php

namespace App\Models;

use App\Support\Audit\AuditTrail;
use DomainException;
use Spatie\Activitylog\Models\Activity;

/**
 * Restricts model writes to the chain writer and rejects updates or deletion of audit evidence.
 */
class AuditEvent extends Activity
{
    public $incrementing = false;

    /**
     * Rejects model writes that would bypass the protected financial evidence path.
     */
    protected static function booted(): void
    {
        static::saving(function (): void {
            if (! app(AuditTrail::class)->isWriting()) {
                throw new DomainException('Audit history must use the serialized chain writer.');
            }
        });
        static::updating(fn () => throw new DomainException('Audit history is immutable.'));
        static::deleting(fn () => throw new DomainException('Audit history is immutable.'));
    }
}
