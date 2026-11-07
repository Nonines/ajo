<?php

namespace Database\Factories;

use App\Contracts\Clock;
use App\Models\SavingsProduct;
use App\Models\SavingsProductVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SavingsProductVersion> */
class SavingsProductVersionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['savings_product_id' => SavingsProduct::factory(), 'version' => 1, 'published_at' => app(Clock::class)->now(),
            'contract' => ['name' => 'Test savings', 'disclosure' => 'Test fixture contract.', 'minimum' => '1', 'maximum' => '100000000000',
                'withholding_bp' => 1000, 'terms' => [['days' => 30, 'annual_rate_bp' => 1000], ['days' => 60, 'annual_rate_bp' => 1200]],
                'interest' => 'simple', 'day_count' => 'Actual/365', 'lock' => 'until_maturity', 'fee' => '0']];
    }
}
