<?php

namespace App\Support;

use App\Models\LedgerAccount;
use App\Models\WithdrawalRequest;

/**
 * Derives spending restrictions and debt-first credit allocations from recovery receivables.
 */
class RecoveryControls
{
    public function __construct(private Ledger $ledger) {}

    public function balance(int $customerId): int
    {
        return $this->ledger->balance(LedgerAccount::where('code', 'customer:'.$customerId.':reversal_receivable')->sole())->kobo;
    }

    public function restricted(int $customerId): bool
    {
        return $this->balance($customerId) > 0;
    }

    /**
     * Cancels unsubmitted payout reservations while the customer has outstanding recovery debt.
     */
    public function activate(int $customerId): void
    {
        if (! $this->restricted($customerId)) {
            return;
        }
        foreach (WithdrawalRequest::where('user_id', $customerId)->whereNull('submitted_at')
            ->whereIn('state', ['requested', 'under_review', 'approved', 'queued_for_payout'])->orderBy('id')->get() as $withdrawal) {
            app(Withdrawals::class)->release($withdrawal, 'cancelled', 'Recovery restriction activated');
        }
    }

    /**
     * Builds credit entries that repay recovery debt before increasing spendable wallet value.
     *
     * @return list<array{account_id: int, amount: Money}>
     */
    public function credits(int $customerId, int $amount): array
    {
        $repaid = min($amount, $this->balance($customerId));
        $entries = [];
        foreach (['reversal_receivable' => $repaid, 'wallet' => $amount - $repaid] as $suffix => $credit) {
            if ($credit > 0) {
                $entries[] = ['account_id' => LedgerAccount::where('code', 'customer:'.$customerId.':'.$suffix)->sole()->id,
                    'amount' => new Money(-$credit)];
            }
        }

        return $entries;
    }
}
