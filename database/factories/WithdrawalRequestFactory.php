<?php

namespace Database\Factories;

use App\Contracts\Clock;
use App\Models\SavedDestination;
use App\Models\WithdrawalRequest;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<WithdrawalRequest> */
class WithdrawalRequestFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        // Read fixtures do not invent financial reservations; lifecycle tests use the domain service.
        return ['saved_destination_id' => SavedDestination::factory(),
            'user_id' => fn (array $attributes): int => SavedDestination::whereKey($attributes['saved_destination_id'])->sole()->user_id,
            'destination' => fn (array $attributes): array => SavedDestination::whereKey($attributes['saved_destination_id'])->sole()->details,
            'request_key' => (string) Str::uuid(), 'reference' => 'ajo-'.Str::uuid(), 'origin' => 'real',
            'principal' => 100000, 'reserved_fee' => 1000, 'threshold' => 10000000, 'state' => 'requested',
            'created_at' => app(Clock::class)->now(), 'expires_at' => app(Clock::class)->now()->addHours(24)];
    }
}
