<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Ledger\Wallets;
use Illuminate\Console\Command;

/**
 * Creates missing wallet and recovery accounts without introducing customer value.
 */
class ProvisionWallets extends Command
{
    protected $signature = 'wallets:provision';

    protected $description = 'Idempotently provision empty wallets for existing customers';

    /**
     * Provisions missing customer accounts in batches without creating opening value.
     */
    public function handle(Wallets $wallets): int
    {
        User::query()->chunkById(100, function ($users) use ($wallets): void {
            foreach ($users as $user) {
                $wallets->provision($user);
            }
        });
        $this->info('Customer wallets provisioned without opening balances.');

        return self::SUCCESS;
    }
}
