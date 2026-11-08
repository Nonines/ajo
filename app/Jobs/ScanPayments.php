<?php

namespace App\Jobs;

use App\Support\PaymentMonitoring;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Resumes checkpointed provider discovery on the webhooks queue.
 */
class ScanPayments implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 45;

    public function __construct(public string $resource)
    {
        $this->onConnection('redis')->onQueue('webhooks')->afterCommit();
    }

    public function handle(PaymentMonitoring $service): void
    {
        $service->scan($this->resource);
    }
}
