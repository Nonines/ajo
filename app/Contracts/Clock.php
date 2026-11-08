<?php

namespace App\Contracts;

use Carbon\CarbonImmutable;

/**
 * Supplies immutable UTC instants independently of financial calendar-date policy.
 */
interface Clock
{
    /**
     * Returns the current instant in UTC; consumers apply the application timezone for economic dates.
     */
    public function now(): CarbonImmutable;
}
