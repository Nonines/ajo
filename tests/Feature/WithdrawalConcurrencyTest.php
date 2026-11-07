<?php

use App\Contracts\Clock;
use App\Jobs\DeliverPaymentNotice;
use App\Jobs\DispatchWithdrawal;
use App\Jobs\ProcessPaymentWebhook;
use App\Jobs\VerifyWithdrawal;
use App\Models\LedgerAccount;
use App\Models\PaymentReversal;
use App\Models\SavedDestination;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Support\AuditTrail;
use App\Support\Deposits;
use App\Support\FrozenClock;
use App\Support\Ledger;
use App\Support\Money;
use App\Support\Withdrawals;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

function payoutWorker(string $database, string $mode, int $id, string $argument = ''): Process
{
    $code = <<<'WORKER'
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $argv[1], 'ledger.lock_store' => 'redis',
    'cache.default' => 'array', 'payments.secret_key' => 'sk_test_fixture', 'audit.keys.v1' => 'test-only-audit-chain-key-at-least-32-bytes']);
Illuminate\Support\Facades\DB::purge('sqlite');
$app->instance(App\Contracts\Clock::class, new App\Support\FrozenClock(Carbon\CarbonImmutable::parse('2026-11-07T10:00:00Z')));
Illuminate\Support\Facades\Queue::fake([App\Jobs\DispatchWithdrawal::class, App\Jobs\VerifyWithdrawal::class, App\Jobs\DeliverPaymentNotice::class]);
try {
    if ($argv[2] === 'request') {
        $saved = App\Models\SavedDestination::findOrFail((int) $argv[3]);
        app(App\Support\Withdrawals::class)->request($saved->user, $saved, new App\Support\Money(100000), $argv[4]);
    } elseif ($argv[2] === 'audit') {
        app(App\Support\AuditTrail::class)->record('concurrent.fixture', App\Models\User::findOrFail((int) $argv[3]));
    } elseif ($argv[2] === 'recover') {
        app(App\Support\Ledger::class)->recoverDeposit(App\Models\PaymentReversal::findOrFail((int) $argv[3]));
    } elseif ($argv[2] === 'approval') {
        $w = App\Models\WithdrawalRequest::findOrFail((int) $argv[3]);
        $allowed = app(App\Support\Withdrawals::class)->providerApproval(['reference' => $w->reference, 'amount' => (string) $w->principal,
            'recipient' => $w->destination['recipient_code'], 'currency' => 'NGN', 'source' => 'balance']);
        echo $allowed ? 'approved' : 'denied';
    } else {
        $w = App\Models\WithdrawalRequest::findOrFail((int) $argv[3]);
        Illuminate\Support\Facades\Http::preventStrayRequests();
        $data = ['id' => $w->id + 20000, 'reference' => $w->reference, 'domain' => 'test', 'currency' => 'NGN', 'status' => 'pending',
            'amount' => $w->principal, 'recipient' => ['recipient_code' => $w->destination['recipient_code']], 'fee_charged' => 0];
        Illuminate\Support\Facades\Http::fake([
            'api.paystack.co/transfer' => function () use ($w, $argv) {
                echo "submitted\n"; flush();
                if ($argv[2] === 'callback') {
                    $child = new Symfony\Component\Process\Process([PHP_BINARY, '-r', $argv[4], $argv[1], 'approval', (string) $w->id], getcwd(), timeout: 5);
                    $child->run(); echo $child->getOutput();
                } else {
                    usleep(750000);
                }
                return Illuminate\Support\Facades\Http::response(['status' => true, 'data' => ['reference' => $w->reference]]);
            },
            'api.paystack.co/transfer/verify/'.$w->reference => Illuminate\Support\Facades\Http::response(['status' => true, 'data' => $data]),
            'api.paystack.co/transfer/'.($w->id + 20000) => Illuminate\Support\Facades\Http::response(['status' => true, 'data' => $data]),
        ]);
        app(App\Support\Withdrawals::class)->dispatch($w);
    }
    echo "success";
} catch (Throwable $exception) {
    echo 'rejected:'.$exception->getMessage();
}
WORKER;

    return new Process([PHP_BINARY, '-r', $code, $database, $mode, (string) $id, $mode === 'callback' ? $code : $argument], base_path(), timeout: 20);
}

test('independent payout workers preserve reservations audit ordering and recovery races', function (string $scenario): void {
    if (getenv('RUN_REDIS_TESTS') !== '1') {
        $this->markTestSkipped('Run with RUN_REDIS_TESTS=1 and real Redis.');
    }
    $database = tempnam(sys_get_temp_dir(), 'ajo-payout-');
    $original = DB::getDefaultConnection();
    config(['database.connections.payout_concurrency' => [...config('database.connections.sqlite'), 'database' => $database]]);
    DB::setDefaultConnection('payout_concurrency');
    app()->instance(Clock::class, new FrozenClock(CarbonImmutable::parse('2026-11-07T10:00:00Z')));
    $workers = [];
    try {
        $this->artisan('migrate', ['--database' => 'payout_concurrency', '--force' => true, '--no-interaction' => true])->assertSuccessful();
        Queue::fake([DispatchWithdrawal::class, VerifyWithdrawal::class, DeliverPaymentNotice::class, ProcessPaymentWebhook::class]);
        $user = User::factory()->create();
        if (in_array($scenario, ['competing', 'replay'], true)) {
            $destination = SavedDestination::factory()->for($user)->create();
            postWalletTestCredit($user, kobo: 101000);
            $key = (string) Str::uuid();
            $workers = [payoutWorker($database, 'request', $destination->id, $key),
                payoutWorker($database, 'request', $destination->id, $scenario === 'replay' ? $key : (string) Str::uuid())];
            foreach ($workers as $worker) {
                $worker->start();
            }
            foreach ($workers as $worker) {
                $worker->wait();
            }
            $outputs = implode(' ', array_map(fn (Process $worker): string => $worker->getOutput(), $workers));
            expect($outputs)->toContain('success');
            if ($scenario === 'competing') {
                expect($outputs)->toContain('Insufficient wallet funds');
            } else {
                expect($outputs)->not->toContain('rejected:');
            }
            expect(WithdrawalRequest::count())->toBe(1);
            expect(app(Ledger::class)->balance(LedgerAccount::where('code', 'customer:'.$user->id.':wallet')->sole())->kobo)->toBe(0);
        } elseif ($scenario === 'audit') {
            $other = User::factory()->create();
            $workers = [payoutWorker($database, 'audit', $user->id), payoutWorker($database, 'audit', $other->id)];
            foreach ($workers as $worker) {
                $worker->start();
            }
            foreach ($workers as $worker) {
                $worker->wait();
                expect($worker->getOutput())->toBe('success');
            }
            expect(app(AuditTrail::class)->verify())->toBe(2);
        } else {
            $deposit = initializePaymentTest($user);
            fakeVerifiedPayment($deposit);
            app(Deposits::class)->verify($deposit);
            Queue::fake([DispatchWithdrawal::class, VerifyWithdrawal::class, DeliverPaymentNotice::class, ProcessPaymentWebhook::class]);
            $destination = SavedDestination::factory()->for($user)->create();
            $w = app(Withdrawals::class)->request($user, $destination, new Money(499000), (string) Str::uuid());
            app(Withdrawals::class)->review(payoutStaff(), $w, true, 'Reviewed');
            $effect = new PaymentReversal;
            $effect->forceFill(['deposit_id' => $deposit->id, 'provider_refund_id' => 'fixture', 'fingerprint' => hash('sha256', 'fixture'),
                'amount' => 500000, 'principal' => 500000, 'fee' => 0, 'evidence' => [], 'verified_at' => now()])->save();
            if ($scenario === 'recovery-first') {
                $workers[] = payoutWorker($database, 'recover', $effect->id);
                $workers[0]->run();
                expect($workers[0]->getOutput())->toBe('success');
                $workers[] = payoutWorker($database, 'dispatch', $w->id);
                $workers[1]->run();
                expect($workers[1]->getOutput())->toBe('success');
                expect((string) $w->fresh()->state)->toBe('cancelled');
                expect(app(Ledger::class)->balance(LedgerAccount::where('code', 'clearing:payouts_in_transit')->sole())->kobo)->toBe(0);
            } elseif ($scenario === 'callback') {
                $workers[] = payoutWorker($database, 'callback', $w->id);
                $workers[0]->run();
                expect($workers[0]->getOutput())->toContain('approvedsuccess');
                expect($w->fresh()->provider_approved_at)->not->toBeNull();
            } else {
                $workers[] = payoutWorker($database, 'dispatch', $w->id);
                $workers[0]->start();
                expect($workers[0]->waitUntil(fn ($type, $output): bool => str_contains($output, 'submitted')))->toBeTrue();
                $workers[] = payoutWorker($database, 'recover', $effect->id);
                $workers[1]->run();
                $workers[0]->wait();
                expect($workers[1]->getOutput())->toBe('success');
                expect((string) $w->fresh()->state)->toBe('queued_for_payout');
                expect(app(Ledger::class)->balance(LedgerAccount::where('code', 'clearing:payouts_in_transit')->sole())->kobo)->toBe(-500000);
                $workers[] = payoutWorker($database, 'approval', $w->id);
                $workers[2]->run();
                expect($workers[2]->getOutput())->toBe('deniedsuccess');
            }
        }
        expect(app(Ledger::class)->projectionMismatches())->toBe([]);
        app(AuditTrail::class)->verify();
    } finally {
        foreach ($workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop();
            }
        }
        DB::disconnect('payout_concurrency');
        DB::setDefaultConnection($original);
        unlink($database);
    }
})->with(['competing', 'replay', 'audit', 'recovery-first', 'dispatch-first', 'callback'])->group('redis');
