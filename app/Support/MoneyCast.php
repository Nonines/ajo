<?php

namespace App\Support;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/** @implements CastsAttributes<Money, Money> */
class MoneyCast implements CastsAttributes
{
    /** @param array<string, mixed> $attributes */
    public function get(Model $model, string $key, mixed $value, array $attributes): Money
    {
        return new Money($value);
    }

    /** @param array<string, mixed> $attributes */
    public function set(Model $model, string $key, mixed $value, array $attributes): int
    {
        if (! $value instanceof Money) {
            throw new \InvalidArgumentException('Monetary attributes require Money.');
        }

        return $value->kobo;
    }
}
