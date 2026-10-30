<?php

namespace App\Support;

use App\Contracts\Clock;
use Carbon\CarbonImmutable;

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
