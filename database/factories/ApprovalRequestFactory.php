<?php

namespace Database\Factories;

use App\Contracts\Clock;
use App\Models\ApprovalRequest;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ApprovalRequest> */
class ApprovalRequestFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['subject_type' => User::class, 'subject_id' => User::factory(), 'action' => 'recovery.release',
            'requested_by' => Staff::factory(), 'state' => 'pending', 'reason' => 'Fixture review', 'payload' => [],
            'expires_at' => app(Clock::class)->now()->addHours(24)];
    }
}
