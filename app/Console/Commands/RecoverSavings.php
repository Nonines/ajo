<?php

namespace App\Console\Commands;

use App\Support\Savings\SavingsProcessing;
use Illuminate\Console\Command;

/**
 * Rediscovers durable savings calculations, lifecycle work, and notice delivery.
 */
class RecoverSavings extends Command
{
    protected $signature = 'savings:recover';

    protected $description = 'Rediscover due savings plans and undelivered maturity notices';

    /**
     * Redispatches due savings work from persisted processing times.
     */
    public function handle(SavingsProcessing $processing): int
    {
        $processing->discover();

        return self::SUCCESS;
    }
}
