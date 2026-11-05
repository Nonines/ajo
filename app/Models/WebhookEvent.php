<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $payload
 * @property string|null $claim_token
 * @property CarbonImmutable|null $processed_at
 * @property CarbonImmutable|null $claimed_at
 * @property int $attempts
 */
#[Hidden(['payload', 'claim_token'])]
class WebhookEvent extends Model
{
    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['payload' => 'encrypted', 'processed_at' => 'immutable_datetime', 'claimed_at' => 'immutable_datetime'];
    }
}
