<?php

use App\Contracts\Clock;
use App\Models\LedgerAccount;
use App\Models\SavingsPlan;
use App\Support\Approvals;
use App\Support\FrozenClock;
use App\Support\Ledger;
use App\Support\SavingsPlans;
use App\Support\SavingsSettlements;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

function savingsWorker(string $database, int $id, string $mode, string $token = ''): Process
{
    $code = <<<'PHPWORKER'
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $argv[1], 'ledger.lock_store' => 'redis']);
Illuminate\Support\Facades\DB::purge('sqlite');
$app->instance(App\Contracts\Clock::class, new App\Support\FrozenClock(Carbon\CarbonImmutable::parse(match ($argv[3]) { 'settle', 'boundary-exit' => '2028-03-30T00:00:00Z', 'exit', 'waiver' => '2028-03-10T10:00:00Z', default => '2028-02-28T00:00:00Z' })));
Illuminate\Support\Facades\Queue::fake([App\Jobs\DeliverSavingsNotice::class]);
try {
    $plan = App\Models\SavingsPlan::findOrFail((int) $argv[2]);
    if ($argv[3] === 'settle') {
        app(App\Support\SavingsProcessing::class)->process($plan->id);
    } elseif (in_array($argv[3], ['exit', 'waiver', 'boundary-exit'], true)) {
        $waiver = $argv[3] === 'waiver' ? Illuminate\Support\Facades\DB::table('plan_waivers')->where('savings_plan_id', $plan->id)->sole()->id : null;
        app(App\Support\SavingsSettlements::class)->terminate($plan->user, $plan, $argv[4], $waiver);
    } else {
        app(App\Support\SavingsPlans::class)->fund($plan->user, $plan);
    }
    echo "success";
} catch (Throwable $exception) {
    echo 'rejected:'.$exception->getMessage();
}
PHPWORKER;

    return new Process([PHP_BINARY, '-r', $code, $database, (string) $id, $mode, $token], base_path(), timeout: 20);
}

test('independent savings workers serialize funding and maturity without duplicate effects', function (string $scenario) {
    if (getenv('RUN_REDIS_TESTS') !== '1') {
        $this->markTestSkipped('Run with RUN_REDIS_TESTS=1 and real Redis.');
    }
    $database = tempnam(sys_get_temp_dir(), 'ajo-savings-');
    $original = DB::getDefaultConnection();
    config(['database.connections.savings_concurrency' => [...config('database.connections.sqlite'), 'database' => $database]]);
    DB::setDefaultConnection('savings_concurrency');
    app()->instance(Clock::class, new FrozenClock(CarbonImmutable::parse('2028-02-28T00:00:00Z')));
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

test('independent workers serialize early closure waiver consumption rollover and the maturity race', function (string $scenario): void {
    if (getenv('RUN_REDIS_TESTS') !== '1') {
        $this->markTestSkipped('Run with RUN_REDIS_TESTS=1 and real Redis.');
    }
    $database = tempnam(sys_get_temp_dir(), 'ajo-closure-');
    $original = DB::getDefaultConnection();
    config(['database.connections.closure_concurrency' => [...config('database.connections.sqlite'), 'database' => $database]]);
    DB::setDefaultConnection('closure_concurrency');
    app()->instance(Clock::class, new FrozenClock(CarbonImmutable::parse('2028-02-28T00:00:00Z')));
    $workers = [];
    try {
        $this->artisan('migrate', ['--database' => 'closure_concurrency', '--force' => true, '--no-interaction' => true])->assertSuccessful();
        $plan = fundedAccrualPlan(changes: ['interest' => 'daily', 'lock' => 'terminable', 'minimum_holding_days' => 1,
            'forfeiture_bp' => 5000, 'principal_penalty_bp' => 100]);
        $token = '';
        if ($scenario === 'rollover') {
            app(SavingsPlans::class)->electRollover($plan->user, $plan, true, 30, 'all_net', true);
        } else {
            app(Clock::class)->set(CarbonImmutable::parse('2028-03-10T10:00:00Z'));
            $waiverId = null;
            if ($scenario === 'waiver') {
                $approval = app(Approvals::class)->propose(payoutStaff(), 'plan.waive', $plan,
                    ['interest_cap' => '100', 'principal_cap' => '100'], 'Capped concurrency waiver');
                app(Approvals::class)->decide(payoutStaff(), $approval, true, 'Independent check');
                $waiverId = DB::table('plan_waivers')->sole()->id;
            }
            $token = app(SavingsSettlements::class)->preview($plan->user, $plan, $waiverId)['token'];
        }
        $modes = match ($scenario) {
            'rollover' => ['settle', 'settle'], 'boundary' => ['boundary-exit', 'settle'], default => [$scenario, $scenario],
        };
        foreach ($modes as $mode) {
            $worker = savingsWorker($database, $plan->id, $mode, $token);
            $workers[] = $worker;
            $worker->start();
        }
        foreach ($workers as $worker) {
            $worker->wait();
            expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput());
        }
        $outputs = array_map(fn (Process $worker): string => $worker->getOutput(), $workers);
        if ($scenario === 'boundary') {
            expect(implode(' ', $outputs))->toContain('success')->toContain('rejected:');
        } else {
            expect($outputs)->toBe(['success', 'success']);
        }
        $this->assertDatabaseCount('plan_settlements', 1);
        expect($plan->fresh()->closure_reason)->toBe(match ($scenario) {
            'rollover' => 'rolled_over', 'boundary' => 'matured_withdrawal', default => 'early_termination',
        });
        if ($scenario === 'rollover') {
            expect(SavingsPlan::where('parent_plan_id', $plan->id)->count())->toBe(1);
        }
        if ($scenario === 'waiver') {
            expect(DB::table('plan_waivers')->sole()->used_at)->not->toBeNull();
        }
        expect(app(Ledger::class)->projectionMismatches())->toBe([]);
    } finally {
        foreach ($workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop();
            }
        }
        DB::disconnect('closure_concurrency');
        DB::setDefaultConnection($original);
        unlink($database);
    }
})->with(['exit', 'waiver', 'rollover', 'boundary'])->group('redis');
