<?php

namespace App\Support\Clock;

use App\Contracts\Clock;
use Carbon\CarbonImmutable;

/**
 * Supplies real UTC instants; callers choose the timezone for economic calendar dates.
 */
class SystemClock implements Clock
{
    /**
     * Returns the real UTC instant independently of application calendar policy.
     */
    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC');
    }
}
