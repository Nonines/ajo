<?php

namespace App\Support\Ledger;

use App\Models\AuditEvent;
use App\Models\Deposit;
use App\Models\LedgerAccount;
use App\Models\SavedDestination;
use App\Models\SavingsPlan;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Support\Audit\AuditTrail;
use Illuminate\Support\Facades\DB;

/**
 * Provisions customer ledger accounts and closes accounts while retaining financial evidence.
 */
class Wallets
{
    public function __construct(private FinancialTransaction $transactions) {}

    /**
     * Ensures wallet liability and recovery receivable accounts exist without adding opening value.
     */
    public function provision(User $user): LedgerAccount
    {
        return $this->transactions->run([$user->id], function () use ($user): LedgerAccount {
            User::findOrFail($user->id);
            DB::table('ledger_accounts')->insertOrIgnore([
                'code' => 'customer:'.$user->id.':wallet', 'user_id' => $user->id, 'type' => 'liability',
            ]);
            DB::table('ledger_accounts')->insertOrIgnore([
                'code' => 'customer:'.$user->id.':reversal_receivable', 'user_id' => $user->id, 'type' => 'asset',
            ]);

            return LedgerAccount::where('code', 'customer:'.$user->id.':wallet')->firstOrFail();
        });
    }

    /**
     * Records closure, then deactivates customers with retained history instead of deleting them.
     */
    public function close(User $user): void
    {
        $this->transactions->run([$user->id], function () use ($user): void {
            $customer = User::findOrFail($user->id);
            app(AuditTrail::class)->record('customer.account_closed', $customer, actor: $customer);
            $accounts = LedgerAccount::where('user_id', $customer->id);

            // Keep the customer identity whenever financial or audit evidence still refers to it.
            $hasHistory = (clone $accounts)->whereHas('entries')->exists() || Deposit::where('user_id', $customer->id)->exists()
                || SavingsPlan::where('user_id', $customer->id)->exists()
                || SavedDestination::where('user_id', $customer->id)->exists()
                || WithdrawalRequest::where('user_id', $customer->id)->exists()
                || DB::table('audit_customer_references')->where('user_id', $customer->id)->exists()
                || AuditEvent::where(fn ($query) => $query->where('subject_type', $customer->getMorphClass())->where('subject_id', $customer->id))
                    ->orWhere(fn ($query) => $query->where('causer_type', $customer->getMorphClass())->where('causer_id', $customer->id))->exists();

            if ($hasHistory) {
                $customer->forceFill(['is_active' => false, 'remember_token' => null])->save();
                $customer->passkeys()->delete();

                return;
            }

            $accounts->delete();
            $customer->passkeys()->delete();
            $customer->delete();
        });
    }
}
