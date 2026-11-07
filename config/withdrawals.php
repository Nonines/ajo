<?php

return [
    'approval_token' => env('PAYSTACK_APPROVAL_TOKEN'),
    'threshold' => 10000000,
    'review_hours' => 24,
    'approval_hours' => 24,
    'fee_tiers' => [[500000, 1000], [5000000, 2500], [PHP_INT_MAX, 5000]],
    'simulation_enabled' => env('PAYMENT_SIMULATION_ENABLED', false),
    'simulation_signing_key' => env('SIMULATOR_SIGNING_KEY'),
];
