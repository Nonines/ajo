<?php

namespace App\Models;

use App\Support\Money;
use App\Support\MoneyCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Identifies an account and its rebuildable signed balance; wallet liabilities are credit-negative.
 *
 * @property int $id
 * @property string $code
 * @property string $type
 * @property string $currency
 * @property int|null $user_id
 * @property Money $balance
 */
class LedgerAccount extends Model
{
    public $timestamps = false;

    /** @return HasMany<LedgerEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class, 'account_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['balance' => MoneyCast::class];
    }
}
