<?php

namespace App\Jobs;

use App\Models\Deposit;
use App\Support\Deposits;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Requests authoritative payment evidence for a saved deposit on the webhooks queue.
 */
class VerifyDeposit implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 45;

    public function __construct(public int $depositId)
    {
        $this->onConnection('redis')->onQueue('webhooks')->afterCommit();
    }

    public function handle(Deposits $service): void
    {
        $service->verify(Deposit::findOrFail($this->depositId));
    }
}
