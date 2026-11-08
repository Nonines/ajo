<?php

namespace App\Support\Clock;

use App\Contracts\Clock;
use Carbon\CarbonImmutable;

/**
 * Provides a controllable UTC instant for deterministic financial lifecycle tests.
 */
class FrozenClock implements Clock
{
    public function __construct(private CarbonImmutable $instant) {}

    /**
     * Normalizes the controlled instant to UTC for domain consumers.
     */
    public function now(): CarbonImmutable
    {
        return $this->instant->utc();
    }

    /**
     * Changes the test instant without changing the application clock contract.
     */
    public function set(CarbonImmutable $instant): void
    {
        $this->instant = $instant;
    }
}
