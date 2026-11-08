<?php

use App\Models\AuditEvent;
use App\Support\Audit\LogProtectedActivity;
use App\Support\Audit\PreventAuditCleanup;

return [
    'enabled' => true,
    'clean_after_days' => 365,
    'default_log_name' => 'audit',
    'default_auth_driver' => null,
    'include_soft_deleted_subjects' => false,
    'activity_model' => AuditEvent::class,
    'default_except_attributes' => ['password', 'remember_token', 'two_factor_secret', 'app_authentication_secret'],
    'buffer' => ['enabled' => false],
    'actions' => [
        'log_activity' => LogProtectedActivity::class,
        'clean_log' => PreventAuditCleanup::class,
    ],
];
