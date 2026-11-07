<?php

return [
    'key_version' => 'v1',
    'keys' => ['v1' => env('AUDIT_CHAIN_KEY')],
    'online_days' => 365,
    'retention_years' => 7,
    'archive_disk' => 'local',
];
