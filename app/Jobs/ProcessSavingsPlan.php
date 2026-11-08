<?php

namespace App\Jobs;

use App\Contracts\Clock;
use App\Models\SavingsPlan;
use App\Support\Savings\SavingsProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Processes a committed plan on the accrual queue and retains a retry time on failure.
 */
class ProcessSavingsPlan implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 45;

    public function __construct(public int $planId)
    {
        $this->onConnection('redis')->onQueue('accrual')->afterCommit();
    }

    /**
     * Retains the next processing time before surfacing a worker failure for reporting.
     */
    public function handle(SavingsProcessing $processing, Clock $clock): void
    {
        try {
            $processing->process($this->planId);
        } catch (Throwable $exception) {
            SavingsPlan::whereKey($this->planId)->whereIn('state', ['active', 'suspended', 'matured'])
                ->update(['next_process_at' => $clock->now()->addMinutes(config('savings.retry_minutes'))]);
            throw $exception;
        }
    }
}
