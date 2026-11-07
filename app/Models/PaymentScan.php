<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $resource
 * @property int $next_page
 * @property CarbonImmutable|null $window_from
 * @property CarbonImmutable|null $window_to
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $scheduled_at
 * @property CarbonImmutable|null $observed_through
 * @property CarbonImmutable|null $next_attempt_at
 */
class PaymentScan extends Model
{
    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['next_page' => 'integer', 'window_from' => 'immutable_datetime', 'window_to' => 'immutable_datetime', 'completed_at' => 'immutable_datetime', 'scheduled_at' => 'immutable_datetime', 'observed_through' => 'immutable_datetime', 'next_attempt_at' => 'immutable_datetime'];
    }
}
