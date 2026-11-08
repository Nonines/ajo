<?php

return [
    'funding_hours' => 24,
    'withholding_bp' => 1000,
    // Bound each accrual pass; unfinished plans retain a due time for scheduler recovery.
    'processing_days' => 30,
    'retry_minutes' => 5,
    'waiver_hours' => 24,
    // Bound entitlement calculation work separately from the number of dated ledger accruals.
    'preparation_steps' => 1000,
];
