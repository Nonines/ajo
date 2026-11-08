<?php

namespace App\Support\Audit;

use App\Models\AuditEvent;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Actions\LogActivityAction;

/**
 * Routes activity-log writes through the serialized, redacted audit chain.
 */
class LogProtectedActivity extends LogActivityAction
{
    /**
     * Requires the protected model and appends it through the chain writer.
     */
    public function execute(Model $activity, string $description): Model
    {
        if (! $activity instanceof AuditEvent) {
            throw new DomainException('Protected audit model is required.');
        }

        $activity->description = $description;
        $activity->event ??= $description;

        return app(AuditTrail::class)->append($activity);
    }
}
