<?php

namespace App\Support\Ledger;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Blocks Eloquent insert helpers that could bypass the ledger posting service.
 *
 * @template TModel of Model
 *
 * @extends Builder<TModel>
 */
class ReadOnlyLedgerBuilder extends Builder
{
    /**
     * Rejects forwarded insert operations while leaving ordinary read operations to Eloquent.
     *
     * @param  array<mixed>  $parameters
     */
    public function __call($method, $parameters): mixed
    {
        if (str_starts_with(strtolower($method), 'insert') || $method === 'updateOrInsert') {
            throw new LogicException('Use the ledger posting service.');
        }

        return parent::__call($method, $parameters);
    }

    /**
     * Rejects bulk upserts because ledger history must be created through balanced postings.
     *
     * @param  array<mixed>  $values
     * @param  array<string>|string  $uniqueBy
     * @param  array<string>|null  $update
     */
    public function upsert(array $values, $uniqueBy, $update = null): int
    {
        throw new LogicException('Use the ledger posting service.');
    }
}
