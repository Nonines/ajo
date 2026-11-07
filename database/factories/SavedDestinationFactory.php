<?php

namespace Database\Factories;

use App\Models\SavedDestination;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SavedDestination> */
class SavedDestinationFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'label' => 'Saved destination', 'origin' => 'real', 'details' => [
            'account_number' => '0123456789', 'bank_code' => '058', 'account_name' => fake()->name(),
            'bank_name' => 'Fixture Bank', 'recipient_code' => 'RCP_'.fake()->unique()->numerify('#########'),
        ]];
    }
}
