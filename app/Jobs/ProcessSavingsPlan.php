<?php

namespace App\Jobs;

use App\Contracts\Clock;
use App\Models\SavingsPlan;
use App\Support\SavingsProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessSavingsPlan implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 45;

    public function __construct(public int $planId)
    {
        $this->onConnection('redis')->onQueue('accrual')->afterCommit();
    }

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
