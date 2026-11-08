<?php

namespace App\Jobs;

use App\Support\Payments\PaymentNotices;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Delivers a durable payment notice after commit; scheduler recovery handles unfinished intent.
 */
class DeliverPaymentNotice implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 45;

    public function __construct(public string $noticeId)
    {
        $this->onConnection('redis')->onQueue('default')->afterCommit();
    }

    /**
     * Delivers the saved notice through the service's deduplication and retry rules.
     */
    public function handle(PaymentNotices $service): void
    {
        $service->deliver($this->noticeId);
    }
}
