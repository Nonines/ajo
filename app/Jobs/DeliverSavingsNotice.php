<?php

namespace App\Jobs;

use App\Support\SavingsNotices;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DeliverSavingsNotice implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 45;

    public function __construct(public string $noticeId)
    {
        $this->onConnection('redis')->onQueue('default')->afterCommit();
    }

    public function handle(SavingsNotices $notices): void
    {
        $notices->deliver($this->noticeId);
    }
}
