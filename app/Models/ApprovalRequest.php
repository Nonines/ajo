<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ApprovalRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Retains an immutable maker proposal alongside the distinct checker decision and application evidence.
 *
 * @property array<string, mixed> $payload
 * @property array<string, mixed>|null $evidence
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $decided_at
 * @property CarbonImmutable|null $applied_at
 */
class ApprovalRequest extends Model
{
    /** @use HasFactory<ApprovalRequestFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'evidence' => 'array', 'expires_at' => 'immutable_datetime', 'decided_at' => 'immutable_datetime', 'applied_at' => 'immutable_datetime'];
    }
}
