<?php

namespace App\Console\Commands;

use App\Support\Ledger\Ledger;
use Illuminate\Console\Command;

/**
 * Checks derived balance projections and optionally rebuilds them from immutable ledger entries.
 */
class LedgerProjections extends Command
{
    protected $signature = 'ledger:projections {--rebuild : Rebuild projections from immutable entries}';

    protected $description = 'Verify or rebuild ledger balance projections';

    /**
     * Optionally rebuilds projections and reports any remaining ledger mismatches.
     */
    public function handle(Ledger $ledger): int
    {
        if ($this->option('rebuild')) {
            $ledger->rebuildProjections();
        }

        $mismatches = $ledger->projectionMismatches();

        if ($mismatches !== []) {
            $this->error('Projection mismatch for accounts: '.implode(', ', $mismatches));

            return self::FAILURE;
        }

        $this->info('All balance projections match the ledger.');

        return self::SUCCESS;
    }
}
