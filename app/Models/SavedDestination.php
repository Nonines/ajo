<?php

namespace App\Models;

use Database\Factories\SavedDestinationFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stores encrypted bank details whose identity and origin are protected by database triggers.
 *
 * @property array<string, mixed> $details
 */
#[Hidden(['details'])]
class SavedDestination extends Model
{
    /** @use HasFactory<SavedDestinationFactory> */
    use HasFactory;

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['details' => 'encrypted:array'];
    }
}
