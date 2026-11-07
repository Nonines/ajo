<?php

namespace App\Models;

use App\Support\AuditTrail;
use DomainException;
use Spatie\Activitylog\Models\Activity;

class AuditEvent extends Activity
{
    public $incrementing = false;

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
