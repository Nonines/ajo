<?php

namespace App\Jobs;

use App\Models\WithdrawalRequest;
use App\Support\Withdrawals;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Reconciles a submitted payout on the payouts queue before considering an eligible retry.
 */
class VerifyWithdrawal implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $withdrawalId)
    {
        $this->onConnection('redis')->onQueue('payouts')->afterCommit();
    }

    public function handle(Withdrawals $withdrawals): void
    {
        $withdrawals->reconcile(WithdrawalRequest::findOrFail($this->withdrawalId));
    }
}
