<?php

use App\Models\Journal;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Support\Ledger\Ledger;
use App\Support\Ledger\Wallets;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

function ledgerWorker(string $database, int $userId, int $walletId, int $cashId, string $mode, string $key, int $amount, int $hold = 0, int $lease = 30): Process
{
    // Use a separate process and shared SQLite file; the holding signal coordinates real lock contention.
    $code = <<<'PHP'
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $argv[1], 'ledger.lock_store' => 'redis', 'ledger.lock_seconds' => (int) $argv[9]]);
Illuminate\Support\Facades\DB::purge('sqlite');
$held = false;
Illuminate\Support\Facades\DB::listen(function ($query) use (&$held, $argv) {
    if (! $held && Illuminate\Support\Facades\DB::transactionLevel() > 0 && str_starts_with(strtolower($query->sql), 'select') && (int) $argv[8] > 0) {
        $held = true;
        echo "holding\n";
        flush();
        usleep((int) $argv[8]);
    }
});
try {
    if ($argv[5] === 'read-lock') {
        Illuminate\Support\Facades\DB::statement('BEGIN DEFERRED TRANSACTION');
        App\Models\LedgerEntry::count();
        echo "holding\n";
        flush();
        usleep(2500000);
        Illuminate\Support\Facades\DB::statement('ROLLBACK');
    } elseif ($argv[5] === 'close') {
        app(App\Support\Ledger\Wallets::class)->close(App\Models\User::findOrFail((int) $argv[2]));
    } else {
        app(App\Support\Ledger\Ledger::class)->post($argv[6], [
            ['account_id' => (int) $argv[3], 'amount' => new App\Support\Money\Money((int) $argv[7])],
            ['account_id' => (int) $argv[4], 'amount' => new App\Support\Money\Money(-(int) $argv[7])],
        ], '2026-11-02', 'Concurrent spend', (int) $argv[2]);
    }
    echo "success\n";
} catch (Throwable $exception) {
    echo 'rejected:'.get_class($exception).':'.$exception->getMessage()."\n";
}
PHP;

    return new Process([PHP_BINARY, '-r', $code, $database, (string) $userId, (string) $walletId, (string) $cashId, $mode, $key, (string) $amount, (string) $hold, (string) $lease], base_path(), timeout: 15);
}

test('independent workers serialize wallet spending replay and deletion', function (string $scenario) {
    if (getenv('RUN_REDIS_TESTS') !== '1') {
        $this->markTestSkipped('Run with RUN_REDIS_TESTS=1 and real Redis.');
    }
    $database = tempnam(sys_get_temp_dir(), 'ajo-ledger-');
    $original = DB::getDefaultConnection();
    config(['database.connections.ledger_concurrency' => [...config('database.connections.sqlite'), 'database' => $database]]);
    DB::setDefaultConnection('ledger_concurrency');
    $workers = [];
    try {
        $this->artisan('migrate', ['--database' => 'ledger_concurrency', '--force' => true, '--no-interaction' => true])->assertSuccessful();
        $user = User::factory()->create();
        $wallet = app(Wallets::class)->provision($user);
        $cash = LedgerAccount::where('code', 'house:cash_at_gateway')->sole();
        if ($scenario !== 'delete-first') {
            postWalletTestCredit($user);
        }
        $firstMode = match ($scenario) {
            'delete-first' => 'close',
            'commit-contention' => 'read-lock',
            default => 'spend',
        };
        $secondMode = $scenario === 'post-first' ? 'close' : 'spend';
        $sameKey = in_array($scenario, ['identical-replay', 'changed-replay']);
        $first = ledgerWorker($database, $user->id, $wallet->id, $cash->id, $firstMode, 'first', 8000, $scenario === 'retry-exhaustion' ? 6500000 : ($scenario === 'expired-lease' ? 2500000 : 300000), in_array($scenario, ['expired-lease', 'retry-exhaustion']) ? 1 : 30);
        $workers[] = $first;
        $first->start();
        expect($first->waitUntil(fn ($type, $output) => str_contains($output, 'holding')))->toBeTrue();
        $second = ledgerWorker($database, $user->id, $wallet->id, $cash->id, $secondMode, $sameKey ? 'first' : 'second', $scenario === 'changed-replay' ? 7000 : 8000);
        $workers[] = $second;
        $second->start();
        $first->wait();
        $second->wait();
        expect($first->getOutput())->toContain('success');
        if (in_array($scenario, ['identical-replay', 'post-first', 'commit-contention'])) {
            expect($second->getOutput())->toContain('success');
        } else {
            expect($second->getOutput())->toContain('rejected:');
        }
        if ($scenario === 'retry-exhaustion') {
            expect($second->getOutput())->toContain('PDOException');
        }
        if ($scenario === 'expired-lease') {
            expect($second->getOutput())->toContain('DomainException:Insufficient wallet funds');
        }
        if ($scenario === 'delete-first') {
            expect(User::findOrFail($user->id)->is_active)->toBeFalse();
            expect(Journal::count())->toBe(0);
        } else {
            expect($wallet->fresh()->balance->kobo)->toBe(-2000);
            expect(Journal::count())->toBe(2);
            expect($user->fresh()->is_active)->toBe($scenario !== 'post-first');
        }
        expect(app(Ledger::class)->projectionMismatches())->toBe([]);
    } finally {
        foreach ($workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop();
            }
        }
        DB::disconnect('ledger_concurrency');
        DB::setDefaultConnection($original);
        unlink($database);
    }
})->with(['overspend', 'identical-replay', 'changed-replay', 'expired-lease', 'delete-first', 'post-first', 'retry-exhaustion', 'commit-contention'])->group('redis');
