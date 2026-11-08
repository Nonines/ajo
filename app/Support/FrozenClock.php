<?php

namespace App\Support;

use App\Contracts\Clock;
use Carbon\CarbonImmutable;

/**
 * Provides a controllable UTC instant for deterministic financial lifecycle tests.
 */
class FrozenClock implements Clock
{
    public function __construct(private CarbonImmutable $instant) {}

    public function now(): CarbonImmutable
    {
        return $this->instant->utc();
    }

    public function set(CarbonImmutable $instant): void
    {
        $this->instant = $instant;
    }
}
