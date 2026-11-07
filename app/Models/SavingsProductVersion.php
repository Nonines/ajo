<?php

namespace App\Models;

use Database\Factories\SavingsProductVersionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $savings_product_id
 * @property int $version
 * @property array{name: string, disclosure: string, minimum: string, maximum: string, withholding_bp: int, terms: list<array{days: int, annual_rate_bp: int}>, interest: string, day_count: string, lock: string, fee: string} $contract
 */
class SavingsProductVersion extends Model
{
    /** @use HasFactory<SavingsProductVersionFactory> */
    use HasFactory;

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['contract' => 'array', 'published_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<SavingsProduct, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(SavingsProduct::class, 'savings_product_id');
    }
}
