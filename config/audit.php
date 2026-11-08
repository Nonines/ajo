<?php

return [
    'key_version' => 'v1',
    // Retain historical key versions while their signed events or archive manifests require verification.
    'keys' => ['v1' => env('AUDIT_CHAIN_KEY')],
    'online_days' => 365,
    'retention_years' => 7,
    'archive_disk' => 'local',
];
