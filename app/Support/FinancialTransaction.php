<?php

namespace App\Support;

use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use LogicException;
use PDOException;
use Throwable;

class FinancialTransaction
{
    /**
     * @template T
     *
     * @param  list<int>  $customerIds
     * @param  Closure(): T  $operation
     * @return T
     */
    public function run(array $customerIds, Closure $operation): mixed
    {
        $customerIds = array_values(array_unique($customerIds));
        sort($customerIds);
        $locks = [];
        try {
            foreach ($customerIds as $id) {
                $store = Cache::store(config('ledger.lock_store'))->getStore();
                if (! $store instanceof LockProvider) {
                    throw new LogicException('The wallet cache store must support locks.');
                }
                $lock = $store->lock('wallet:'.$id, config('ledger.lock_seconds'));
                $lock->block(5);
                $locks[] = $lock;
            }
            $connection = DB::connection();
            if ($connection->getDriverName() !== 'sqlite' || $connection->getConfig('transaction_mode') !== 'IMMEDIATE') {
                throw new LogicException('Financial writes require SQLite IMMEDIATE transactions.');
            }
            $level = $connection->transactionLevel();
            for ($attempt = 0; ; $attempt++) {
                try {
                    return $connection->transaction($operation);
                } catch (Throwable $exception) {
                    if ($connection->transactionLevel() > $level) {
                        $connection->rollBack($level);
                    } elseif ($level === 0 && $connection->getPdo()->inTransaction()) {
                        // SQLite keeps a transaction open after a busy COMMIT.
                        $connection->getPdo()->rollBack();
                    }
                    // Retry the complete outer operation, including a contended BEGIN or COMMIT.
                    if ($level > 0 || $attempt >= 4 || ! $exception instanceof PDOException || ! preg_match('/database (is locked|is busy|table is locked)/i', $exception->getMessage())) {
                        throw $exception;
                    }
                    Sleep::usleep(20000 * ($attempt + 1));
                }
            }
        } finally {
            foreach (array_reverse($locks) as $lock) {
                $lock->release();
            }
        }
    }
}
