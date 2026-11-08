<?php

namespace App\Jobs;

use App\Support\Savings\SavingsNotices;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Delivers a durable savings notice after commit; discovery recovers unsent intent.
 */
class DeliverSavingsNotice implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 45;

    public function __construct(public string $noticeId)
    {
        $this->onConnection('redis')->onQueue('default')->afterCommit();
    }

    /**
     * Delivers the saved savings notice without repeating its financial effect.
     */
    public function handle(SavingsNotices $notices): void
    {
        $notices->deliver($this->noticeId);
    }
}
