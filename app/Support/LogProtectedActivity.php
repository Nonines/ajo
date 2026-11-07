<?php

namespace App\Support;

use App\Models\AuditEvent;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Actions\LogActivityAction;

class LogProtectedActivity extends LogActivityAction
{
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
