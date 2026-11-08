<?php

use App\Contracts\Clock;
use App\Support\FrozenClock;
use Carbon\CarbonImmutable;

test('the system clock returns the current immutable UTC instant', function () {
    $this->travelTo(CarbonImmutable::parse('2028-02-29 23:30:00', 'UTC'));

    $now = app(Clock::class)->now();

    expect($now)->toBeInstanceOf(CarbonImmutable::class)
        ->and($now->toIso8601String())->toBe('2028-02-29T23:30:00+00:00')
        ->and($now->toDateString())->toBe('2028-02-29');
});

test('the frozen clock can advance deterministically across the UTC date boundary', function () {
    $clock = new FrozenClock(CarbonImmutable::parse('2026-10-30 23:59:59', 'UTC'));
    $before = $clock->now();
    $clock->set($before->addSecond());

    expect($before->toIso8601String())->toBe('2026-10-30T23:59:59+00:00')
        ->and($clock->now()->toIso8601String())->toBe('2026-10-31T00:00:00+00:00')
        ->and($clock->now()->toDateString())->toBe('2026-10-31')
        ->and($before->toDateString())->toBe('2026-10-30');
});
