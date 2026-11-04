<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @template TModel of Model
 *
 * @extends Builder<TModel>
 */
class ReadOnlyLedgerBuilder extends Builder
{
    /** @param array<mixed> $parameters */
    public function __call($method, $parameters): mixed
    {
        if (str_starts_with(strtolower($method), 'insert') || $method === 'updateOrInsert') {
            throw new LogicException('Use the ledger posting service.');
        }

        return parent::__call($method, $parameters);
    }

    /**
     * @param  array<mixed>  $values
     * @param  array<string>|string  $uniqueBy
     * @param  array<string>|null  $update
     */
    public function upsert(array $values, $uniqueBy, $update = null): int
    {
        throw new LogicException('Use the ledger posting service.');
    }
}
