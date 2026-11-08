<?php

namespace App\Jobs;

use App\Support\SavingsAccrual;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PrepareSavingsCalculation implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 45;

    public function __construct(public string $fingerprint)
    {
        $this->onConnection('redis')->onQueue('accrual')->afterCommit();
    }

    public function handle(SavingsAccrual $accrual): void
    {
        if (! $accrual->prepareCalculation($this->fingerprint)) {
            self::dispatch($this->fingerprint);
        }
    }
}
