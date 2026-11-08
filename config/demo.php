<?php

return [
    // The first demo clock starts here; an omitted value starts 400 days before setup.
    'start_at' => env('DEMO_START_AT'),
    'customer_email' => 'demo@example.test',
    'customer_password' => env('DEMO_CUSTOMER_PASSWORD'),
    'history_days' => 400,
    'maximum_advance_days' => 365,
    // Each request does bounded work; the lease comfortably exceeds one processing chunk.
    'chunk_seconds' => 2,
    'lock_seconds' => 600,
    'maximum_passes' => 100,
];
