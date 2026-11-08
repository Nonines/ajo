<?php

namespace App\Models;

use App\Support\Ledger\ReadOnlyLedgerBuilder;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Records a signed whole-kobo debit or credit that belongs to an immutable journal.
 *
 * @property int $id
 * @property int $journal_id
 * @property int $account_id
 * @property Money $amount
 * @property Journal $journal
 */
#[UseEloquentBuilder(ReadOnlyLedgerBuilder::class)]
class LedgerEntry extends Model
{
    public $timestamps = false;

    /**
     * Rejects model writes that would bypass the protected financial evidence path.
     */
    protected static function booted(): void
    {
        static::saving(fn () => throw new LogicException('Use the ledger posting service.'));
        static::deleting(fn () => throw new LogicException('Ledger history is immutable.'));
    }

    /** @return BelongsTo<Journal, $this> */
    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['amount' => MoneyCast::class];
    }
}
