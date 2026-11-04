<?php

namespace App\Models;

use App\Support\Money;
use App\Support\MoneyCast;
use App\Support\ReadOnlyLedgerBuilder;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
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
