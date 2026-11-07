<?php

namespace Database\Factories;

use App\Contracts\Clock;
use App\Models\SavingsPlan;
use App\Models\SavingsProductVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<SavingsPlan> */
class SavingsPlanFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'savings_product_version_id' => SavingsProductVersion::factory(),
            'request_key' => (string) Str::uuid(), 'state' => 'draft', 'created_at' => app(Clock::class)->now(),
            'funding_deadline' => app(Clock::class)->now()->addHours(24),
            'contract' => fn (array $attributes): array => SavingsProductVersion::whereKey($attributes['savings_product_version_id'])->sole()->contract
                + ['principal' => '1000000', 'days' => 30, 'annual_rate_bp' => 1000]];
    }
}
