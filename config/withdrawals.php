<?php

return [
    'approval_token' => env('PAYSTACK_APPROVAL_TOKEN'),
    // Two-staff review applies when principal plus the reserved fee exceeds this integer-kobo threshold.
    'threshold' => 10000000,
    'review_hours' => 24,
    'approval_hours' => 24,
    // Each pair is an inclusive principal ceiling and quoted fee, both in integer kobo.
    'fee_tiers' => [[500000, 1000], [5000000, 2500], [PHP_INT_MAX, 5000]],
    'simulation_enabled' => env('PAYMENT_SIMULATION_ENABLED', false),
    'simulation_signing_key' => env('SIMULATOR_SIGNING_KEY'),
];
