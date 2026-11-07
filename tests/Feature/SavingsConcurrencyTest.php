<?php

use App\Contracts\Clock;
use App\Models\LedgerAccount;
use App\Models\SavingsPlan;
use App\Support\FrozenClock;
use App\Support\Ledger;
use App\Support\SavingsPlans;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

function savingsWorker(string $database, int $id, string $mode): Process
{
    $code = <<<'PHPWORKER'
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $argv[1], 'ledger.lock_store' => 'redis']);
Illuminate\Support\Facades\DB::purge('sqlite');
$app->instance(App\Contracts\Clock::class, new App\Support\FrozenClock(Carbon\CarbonImmutable::parse($argv[3] === 'settle' ? '2028-03-30T00:00:00Z' : '2028-02-27T23:00:00Z')));
Illuminate\Support\Facades\Queue::fake([App\Jobs\DeliverSavingsNotice::class]);
try {
    $plan = App\Models\SavingsPlan::findOrFail((int) $argv[2]);
    if ($argv[3] === 'settle') {
        app(App\Support\SavingsProcessing::class)->process($plan->id);
    } else {
        app(App\Support\SavingsPlans::class)->fund($plan->user, $plan);
    }
    echo "success";
} catch (Throwable $exception) {
    echo 'rejected:'.$exception->getMessage();
}
PHPWORKER;

    return new Process([PHP_BINARY, '-r', $code, $database, (string) $id, $mode], base_path(), timeout: 20);
}

test('independent savings workers serialize funding and maturity without duplicate effects', function (string $scenario) {
    if (getenv('RUN_REDIS_TESTS') !== '1') {
        $this->markTestSkipped('Run with RUN_REDIS_TESTS=1 and real Redis.');
    }
    $database = tempnam(sys_get_temp_dir(), 'ajo-savings-');
    $original = DB::getDefaultConnection();
    config(['database.connections.savings_concurrency' => [...config('database.connections.sqlite'), 'database' => $database]]);
    DB::setDefaultConnection('savings_concurrency');
    app()->instance(Clock::class, new FrozenClock(CarbonImmutable::parse('2028-02-27T23:00:00Z')));
    $workers = [];
    try {
        $this->artisan('migrate', ['--database' => 'savings_concurrency', '--force' => true, '--no-interaction' => true])->assertSuccessful();
        $first = SavingsPlan::factory()->create();
        postWalletTestCredit($first->user, kobo: 1000000);
        app(SavingsPlans::class)->accept($first->user, $first);
        $second = $scenario === 'competing' ? SavingsPlan::factory()->create(['user_id' => $first->user_id]) : $first;
        if ($scenario === 'competing') {
            app(SavingsPlans::class)->accept($second->user, $second);
        }
        if ($scenario === 'settle') {
            app(SavingsPlans::class)->fund($first->user, $first);
        }
        foreach ([$first, $second] as $plan) {
            $worker = savingsWorker($database, $plan->id, $scenario === 'settle' ? 'settle' : 'fund');
            $workers[] = $worker;
            $worker->start();
        }
        foreach ($workers as $worker) {
            $worker->wait();
        }
        $outputs = array_map(fn (Process $worker): string => $worker->getOutput(), $workers);
        expect(implode(' ', $outputs))->toContain('success');
        if ($scenario === 'competing') {
            expect(implode(' ', $outputs))->toContain('Insufficient wallet funds');
        } else {
            expect($outputs)->toBe(['success', 'success']);
        }
        expect(SavingsPlan::whereNotNull('funding_journal_id')->count())->toBe(1);
        expect(LedgerAccount::where('code', 'customer:'.$first->user_id.':wallet')->sole()->balance->kobo)->toBe($scenario === 'settle' ? -1007397 : 0);
        expect(DB::table('plan_settlements')->count())->toBe($scenario === 'settle' ? 1 : 0);
        expect(app(Ledger::class)->projectionMismatches())->toBe([]);
    } finally {
        foreach ($workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop();
            }
        }
        DB::disconnect('savings_concurrency');
        DB::setDefaultConnection($original);
        unlink($database);
    }
})->with(['same-plan', 'competing', 'settle'])->group('redis');
