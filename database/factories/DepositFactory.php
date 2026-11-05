<?php

namespace Database\Factories;

use App\Models\Deposit;
use App\Models\User;
use App\Support\DepositStates\PendingConfirmation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Deposit> */
class DepositFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'request_key' => (string) Str::uuid(), 'reference' => 'ajo-'.Str::uuid(),
            'payer_email' => fn (array $attributes): string => User::whereKey($attributes['user_id'])->sole()->email,
            'requested_net' => 500000, 'state' => PendingConfirmation::class];
    }
}
