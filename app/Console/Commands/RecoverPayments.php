<?php

namespace App\Console\Commands;

use App\Support\Payments\PaymentMonitoring;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Rediscovers durable payment work without assuming that queue delivery succeeded.
 */
class RecoverPayments extends Command
{
    protected $signature = 'payments:recover';

    protected $description = 'Recover payment inbox work, verification, notices, and provider discovery';

    /**
     * Requires the payment schema before rediscovering unfinished work.
     */
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
