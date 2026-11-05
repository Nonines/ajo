<?php

namespace App\Jobs;

use App\Support\PaymentInbox;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessPaymentWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 45;

    public function __construct(public int $eventId)
    {
        $this->onConnection('redis')->onQueue('webhooks')->afterCommit();
    }

    public function handle(PaymentInbox $service): void
    {
        $service->process($this->eventId);
    }
}
