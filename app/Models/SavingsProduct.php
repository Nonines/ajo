<?php

namespace App\Models;

use Database\Factories\SavingsProductFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property int $current_version
 */
class SavingsProduct extends Model
{
    /** @use HasFactory<SavingsProductFactory> */
    use HasFactory;

    public $timestamps = false;

    /** @return HasMany<SavingsProductVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(SavingsProductVersion::class);
    }
}
