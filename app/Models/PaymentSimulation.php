<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/** @property array<string, mixed> $evidence */
#[Hidden(['evidence'])]
class PaymentSimulation extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['evidence' => 'encrypted:array'];
    }
}
