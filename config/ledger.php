<?php

return [
    // FinancialTransaction requires a lock-capable store; production workers share these customer locks.
    'lock_store' => 'redis',
    'lock_seconds' => 30,
];
