<?php

namespace App\Jobs;

use App\Models\WithdrawalRequest;
use App\Support\Withdrawals;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DispatchWithdrawal implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $withdrawalId)
    {
        $this->onConnection('redis')->onQueue('payouts')->afterCommit();
    }

    public function handle(Withdrawals $withdrawals): void
    {
        $withdrawals->dispatch(WithdrawalRequest::findOrFail($this->withdrawalId));
    }
}
