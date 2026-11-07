<?php

namespace App\Support;

use App\Models\Deposit;
use App\Models\LedgerAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class Wallets
{
    public function __construct(private FinancialTransaction $transactions) {}

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

    public function close(User $user): void
    {
        $this->transactions->run([$user->id], function () use ($user): void {
            $customer = User::findOrFail($user->id);
            $accounts = LedgerAccount::where('user_id', $customer->id);
            $hasHistory = (clone $accounts)->whereHas('entries')->exists() || Deposit::where('user_id', $customer->id)->exists();
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
