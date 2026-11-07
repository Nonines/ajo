<?php

namespace App\Console\Commands;

use App\Support\PaymentMonitoring;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class RecoverPayments extends Command
{
    protected $signature = 'payments:recover';

    protected $description = 'Recover payment inbox work, verification, notices, and provider discovery';

    public function handle(PaymentMonitoring $monitoring): int
    {
        if (! Schema::hasTable('deposits')) {
            $this->error('Payment migrations have not been applied.');

            return self::FAILURE;
        }
        $monitoring->recover();

        return self::SUCCESS;
    }
}
