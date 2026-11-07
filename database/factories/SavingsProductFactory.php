<?php

namespace Database\Factories;

use App\Models\SavingsProduct;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SavingsProduct> */
class SavingsProductFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['name' => 'Test savings', 'current_version' => 1];
    }
}
