<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Retains verified refund allocation and its linked recovery journal for replay protection.
 *
 * @property int $id
 * @property int $deposit_id
 * @property string $provider_refund_id
 * @property string $fingerprint
 * @property int $amount
 * @property int $principal
 * @property int $fee
 * @property int $wallet_collected
 * @property int $receivable_created
 * @property int|null $journal_id
 * @property Deposit $deposit
 */
#[Hidden(['evidence'])]
class PaymentReversal extends Model
{
    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['evidence' => 'encrypted:array', 'amount' => 'integer', 'principal' => 'integer', 'fee' => 'integer', 'wallet_collected' => 'integer', 'receivable_created' => 'integer', 'verified_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Deposit, $this> */
    public function deposit(): BelongsTo
    {
        return $this->belongsTo(Deposit::class);
    }
}
