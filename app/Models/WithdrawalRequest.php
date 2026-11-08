<?php

namespace App\Models;

use App\Support\Withdrawals\WithdrawalStates\WithdrawalState;
use Carbon\CarbonImmutable;
use Database\Factories\WithdrawalRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\ModelStates\HasStates;

/**
 * Retains the payout reservation snapshot and separately verified principal and fee evidence.
 *
 * @property WithdrawalState $state
 * @property array<string, mixed> $destination
 * @property array<string, mixed>|null $verification
 * @property CarbonImmutable|null $last_dispatch_at
 * @property CarbonImmutable|null $submitted_at
 * @property CarbonImmutable|null $approved_at
 * @property CarbonImmutable|null $provider_approved_at
 * @property CarbonImmutable|null $last_verified_at
 * @property CarbonImmutable|null $next_verify_at
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable $created_at
 */
#[Hidden(['destination', 'verification'])]
class WithdrawalRequest extends Model
{
    protected $attributes = ['fee_revision' => 0];

    /** @use HasFactory<WithdrawalRequestFactory> */
    use HasFactory;

    use HasStates;

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<SavedDestination, $this> */
    public function savedDestination(): BelongsTo
    {
        return $this->belongsTo(SavedDestination::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['state' => WithdrawalState::class, 'destination' => 'encrypted:array', 'verification' => 'encrypted:array', 'principal' => 'integer', 'reserved_fee' => 'integer', 'threshold' => 'integer', 'accounted_fee' => 'integer', 'verified_fee' => 'integer', 'expires_at' => 'immutable_datetime', 'approved_at' => 'immutable_datetime', 'submitted_at' => 'immutable_datetime', 'last_dispatch_at' => 'immutable_datetime', 'provider_approved_at' => 'immutable_datetime', 'last_verified_at' => 'immutable_datetime', 'next_verify_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }
}
