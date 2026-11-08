<?php

namespace App\Support;

use App\Contracts\Clock;
use Carbon\CarbonImmutable;

/**
 * Supplies real UTC instants; callers choose the timezone for economic calendar dates.
 */
class SystemClock implements Clock
{
    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC');
    }
}
