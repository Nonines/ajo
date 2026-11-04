<?php

namespace App\Models;

use App\Support\ReadOnlyLedgerBuilder;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * @property int $id
 * @property string $operation_key
 * @property string $fingerprint
 * @property string $effective_date
 * @property string $posted_at
 * @property string $description
 * @property int|null $reverses_id
 */
#[UseEloquentBuilder(ReadOnlyLedgerBuilder::class)]
class Journal extends Model
{
    public $timestamps = false;

    protected static function booted(): void
    {
        static::saving(fn () => throw new LogicException('Use the ledger posting service.'));
        static::deleting(fn () => throw new LogicException('Journal history is immutable.'));
    }

    /** @return HasMany<LedgerEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }
}
