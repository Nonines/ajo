<?php

namespace App\Console\Commands;

use App\Support\SavingsProcessing;
use Illuminate\Console\Command;

class RecoverSavings extends Command
{
    protected $signature = 'savings:recover';

    protected $description = 'Rediscover due savings plans and undelivered maturity notices';

    public function handle(SavingsProcessing $processing): int
    {
        $processing->discover();

        return self::SUCCESS;
    }
}
