<?php

use App\Models\Deposit;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Support\Ledger\Ledger;
use App\Support\Ledger\Wallets;
use App\Support\Money\Money;
use App\Support\Payments\PaymentInbox;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

function paymentWorker(string $database, int $depositId, string $mode, int $eventId = 0): Process
{
    // Each process boots independently against the shared database and real Redis customer locks.
    $code = <<<'PHP'
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $argv[1], 'ledger.lock_store' => 'redis', 'payments.secret_key' => 'sk_test_fixture']);
Illuminate\Support\Facades\DB::purge('sqlite');
Illuminate\Support\Facades\Queue::fake();
Illuminate\Support\Facades\Http::preventStrayRequests();
$deposit = App\Models\Deposit::findOrFail((int) $argv[2]);
Illuminate\Support\Facades\Http::fake(['api.paystack.co/transaction/verify/'.$deposit->reference => Illuminate\Support\Facades\Http::response(['status' => true, 'data' => [
    'id' => $deposit->id + 1000, 'reference' => $deposit->reference, 'customer' => ['email' => $deposit->payer_email], 'domain' => 'test', 'currency' => 'NGN', 'status' => 'success',
    'amount' => $deposit->requested_net, 'fees' => 0, 'requested_amount' => $deposit->requested_net, 'paid_at' => '2026-11-04T10:00:00Z',
]])]);
try {
    if ($argv[3] === 'webhook') {
        app(App\Support\Payments\PaymentInbox::class)->process((int) $argv[4]);
    } elseif ($argv[3] === 'close') {
        app(App\Support\Ledger\Wallets::class)->close(App\Models\User::findOrFail($deposit->user_id));
    } elseif ($argv[3] === 'sweep') {
        (new App\Jobs\VerifyDeposit($deposit->id))->handle(app(App\Support\Payments\Deposits::class));
    } else {
        app(App\Support\Payments\Deposits::class)->verify($deposit);
    }
    echo "done\n";
} catch (Throwable $exception) {
    echo get_class($exception).':'.$exception->getMessage()."\n";
    exit(1);
}
PHP;

    return new Process([PHP_BINARY, '-r', $code, $database, (string) $depositId, $mode, (string) $eventId], base_path(), timeout: 30);
}

test('independent webhook callback and sweep workers recognize one financial effect while closure retains the destination', function () {
    if (getenv('RUN_REDIS_TESTS') !== '1') {
        $this->markTestSkipped('Run with RUN_REDIS_TESTS=1 and real Redis.');
    }
    $database = tempnam(sys_get_temp_dir(), 'ajo-payment-');
    $original = DB::getDefaultConnection();
    config(['database.connections.payment_concurrency' => [...config('database.connections.sqlite'), 'database' => $database]]);
    DB::setDefaultConnection('payment_concurrency');
    $workers = [];
    try {
        $this->artisan('migrate', ['--database' => 'payment_concurrency', '--force' => true, '--no-interaction' => true])->assertSuccessful();
        $user = User::factory()->create();
        $deposit = initializePaymentTest($user);
        $event = app(PaymentInbox::class)->observe('transaction', ['reference' => $deposit->reference]);
        foreach (['webhook', 'callback', 'sweep', 'close'] as $mode) {
            $worker = paymentWorker($database, $deposit->id, $mode, $event->id);
            $workers[] = $worker;
            $worker->start();
        }
        foreach ($workers as $worker) {
            $worker->wait();
            expect($worker->getOutput())->toContain('done');
            expect($worker->isSuccessful())->toBeTrue();
        }

        $this->assertDatabaseCount('journals', 1);
        $this->assertDatabaseCount('payment_notices', 1);
        expect($deposit->fresh()->wallet_credit)->toBe(500000);
        expect($user->fresh()->is_active)->toBeFalse();
        expect(app(Wallets::class)->provision($user)->balance->kobo)->toBe(-500000);
        expect(app(Ledger::class)->projectionMismatches())->toBe([]);
    } finally {
        foreach ($workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop();
            }
        }
        DB::disconnect('payment_concurrency');
        DB::setDefaultConnection($original);
        unlink($database);
    }
})->group('redis');

test('independent incoming deposits cannot repay the same receivable twice', function () {
    if (getenv('RUN_REDIS_TESTS') !== '1') {
        $this->markTestSkipped('Run with RUN_REDIS_TESTS=1 and real Redis.');
    }
    $database = tempnam(sys_get_temp_dir(), 'ajo-payment-debt-');
    $original = DB::getDefaultConnection();
    config(['database.connections.payment_concurrency' => [...config('database.connections.sqlite'), 'database' => $database]]);
    DB::setDefaultConnection('payment_concurrency');
    $workers = [];
    try {
        $this->artisan('migrate', ['--database' => 'payment_concurrency', '--force' => true, '--no-interaction' => true])->assertSuccessful();
        $user = User::factory()->create();
        app(Wallets::class)->provision($user);
        $receivable = LedgerAccount::where('code', 'customer:'.$user->id.':reversal_receivable')->sole();
        app(Ledger::class)->post('test-prior-recovery', [
            ['account_id' => $receivable->id, 'amount' => new Money(900000)],
            ['account_id' => LedgerAccount::where('code', 'house:cash_at_gateway')->sole()->id, 'amount' => new Money(-900000)],
        ], '2026-11-04', 'Prior recovery fixture', $user->id);
        $first = initializePaymentTest($user);
        $second = initializePaymentTest($user);
        foreach ([$first, $second] as $deposit) {
            $worker = paymentWorker($database, $deposit->id, 'callback');
            $workers[] = $worker;
            $worker->start();
        }
        foreach ($workers as $worker) {
            $worker->wait();
            expect($worker->getOutput())->toContain('done');
            expect($worker->isSuccessful())->toBeTrue();
        }

        expect(app(Ledger::class)->balance($receivable)->kobo)->toBe(0);
        expect(Deposit::sum('debt_repayment'))->toBe(900000);
        expect(app(Wallets::class)->provision($user)->balance->kobo)->toBe(-100000);
        expect(app(Ledger::class)->projectionMismatches())->toBe([]);
    } finally {
        foreach ($workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop();
            }
        }
        DB::disconnect('payment_concurrency');
        DB::setDefaultConnection($original);
        unlink($database);
    }
})->group('redis');
