<?php

namespace App\Jobs;

use App\Support\Payments\PaymentInbox;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Processes a durable inbox receipt on the webhooks queue after its transaction commits.
 */
class ProcessPaymentWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 45;

    public function __construct(public int $eventId)
    {
        $this->onConnection('redis')->onQueue('webhooks')->afterCommit();
    }

    /**
     * Processes the persisted receipt through claim ownership and provider verification.
     */
    public function handle(PaymentInbox $service): void
    {
        $service->process($this->eventId);
    }
}
