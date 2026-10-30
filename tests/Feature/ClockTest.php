<?php

use App\Contracts\Clock;
use App\Support\FrozenClock;
use Carbon\CarbonImmutable;

test('the system clock returns the current immutable UTC instant', function () {
    $this->travelTo(CarbonImmutable::parse('2028-02-29 23:30:00', 'UTC'));

    $now = app(Clock::class)->now();

    expect($now)->toBeInstanceOf(CarbonImmutable::class)
        ->and($now->toIso8601String())->toBe('2028-02-29T23:30:00+00:00')
        ->and($now->setTimezone('Africa/Lagos')->toDateString())->toBe('2028-03-01');
});

test('the frozen clock can advance deterministically across the Lagos date boundary', function () {
    $clock = new FrozenClock(CarbonImmutable::parse('2026-10-30 23:59:59', 'Africa/Lagos'));
    $before = $clock->now();
    $clock->set($before->addSecond());

    expect($before->toIso8601String())->toBe('2026-10-30T22:59:59+00:00')
        ->and($clock->now()->toIso8601String())->toBe('2026-10-30T23:00:00+00:00')
        ->and($clock->now()->setTimezone('Africa/Lagos')->toDateString())->toBe('2026-10-31')
        ->and($before->setTimezone('Africa/Lagos')->toDateString())->toBe('2026-10-30');
});
