<?php

namespace App\Models;

use App\Support\DepositStates\DepositState;
use Carbon\CarbonImmutable;
use Database\Factories\DepositFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\ModelStates\HasStates;

/**
 * @property int $id
 * @property int $user_id
 * @property string $request_key
 * @property string $reference
 * @property string $payer_email
 * @property DepositState $state
 * @property string|null $provider_id
 * @property string|null $access_code
 * @property int $requested_net
 * @property int $gross_amount
 * @property int $fee_amount
 * @property int $wallet_credit
 * @property int $debt_repayment
 * @property int|null $recognition_journal_id
 * @property int|null $fee_journal_id
 * @property string|null $provider_status
 * @property CarbonImmutable|null $recognized_at
 * @property CarbonImmutable|null $last_verified_at
 * @property CarbonImmutable|null $next_verify_at
 * @property CarbonImmutable $created_at
 */
#[Hidden(['payer_email', 'access_code'])]
class Deposit extends Model
{
    /** @use HasFactory<DepositFactory> */
    use HasFactory;

    use HasStates;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'state' => DepositState::class, 'payer_email' => 'encrypted', 'access_code' => 'encrypted',
            'requested_net' => 'integer', 'gross_amount' => 'integer', 'fee_amount' => 'integer',
            'wallet_credit' => 'integer', 'debt_repayment' => 'integer',
            'recognized_at' => 'immutable_datetime', 'last_verified_at' => 'immutable_datetime', 'next_verify_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
