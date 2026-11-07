<?php

namespace App\Jobs;

use App\Support\PaymentNotices;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DeliverPaymentNotice implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 45;

    public function __construct(public string $noticeId)
    {
        $this->onConnection('redis')->onQueue('default')->afterCommit();
    }

    public function handle(PaymentNotices $service): void
    {
        $service->deliver($this->noticeId);
    }
}
