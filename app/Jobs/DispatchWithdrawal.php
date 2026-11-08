<?php

namespace App\Jobs;

use App\Models\WithdrawalRequest;
use App\Support\Withdrawals\Withdrawals;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Dispatches a committed reservation on the payouts queue through the submission-intent checks.
 */
class DispatchWithdrawal implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $withdrawalId)
    {
        $this->onConnection('redis')->onQueue('payouts')->afterCommit();
    }

    /**
     * Reloads the reservation before applying the service's submission checks.
     */
    public function handle(Withdrawals $withdrawals): void
    {
        $withdrawals->dispatch(WithdrawalRequest::findOrFail($this->withdrawalId));
    }
}
