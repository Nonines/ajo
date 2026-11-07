<?php

namespace App\Jobs;

use App\Models\Deposit;
use App\Support\Deposits;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

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
