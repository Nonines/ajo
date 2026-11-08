<?php

use App\Contracts\Clock;
use App\Jobs\ProcessSavingsPlan;
use App\Models\SavingsPlan;
use App\Models\User;
use App\Support\AuditTrail;
use App\Support\DemoHistory;
use App\Support\DemoTimeMachine;
use App\Support\Ledger;
use Database\Seeders\DemoHistorySeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    app()->instance('env', 'demo');
    app()->forgetInstance(Clock::class);
    config(['demo.start_at' => '2025-01-01T23:05:00Z', 'demo.customer_password' => 'demo-test-password',
        'demo.chunk_seconds' => 10, 'withdrawals.simulation_enabled' => true,
        'withdrawals.simulation_signing_key' => str_repeat('s', 32)]);
    $previousEnv = $_ENV['APP_ENV'] ?? null;
    $previousServer = $_SERVER['APP_ENV'] ?? null;
    $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'demo';
    config(['queue' => require config_path('queue.php')]);
    unset($_ENV['APP_ENV'], $_SERVER['APP_ENV']);
    if ($previousEnv !== null) {
        $_ENV['APP_ENV'] = $previousEnv;
    }
    if ($previousServer !== null) {
        $_SERVER['APP_ENV'] = $previousServer;
    }
});

test('the history seeder creates real accrued settled and active plans with a verified audit chain', function (): void {
    Http::preventStrayRequests();
    Notification::fake();
    payoutStaff('admin');
    config(['savings.preparation_steps' => 50]);

    $this->seed(DemoHistorySeeder::class);

    expect(app(DemoTimeMachine::class)->state()['seeded_at'])->not->toBeNull();
    $simple = SavingsPlan::where('request_key', 'demo:history:simple')->sole();
    $compound = SavingsPlan::where('request_key', 'demo:history:compound')->sole();
    $near = SavingsPlan::where('request_key', 'demo:history:near-maturity')->sole();
    expect((string) $simple->state)->toBe('closed');
    expect((string) $compound->state)->toBe('closed');
    expect((string) $near->state)->toBe('active');
    expect(SavingsPlan::where('request_key', 'demo:history:early')->sole()->closure_reason)->toBe('early_termination');
    expect(SavingsPlan::whereNotNull('parent_plan_id')->count())->toBeGreaterThan(0);
    expect(DB::table('plan_accruals')->where('savings_plan_id', $simple->id)->count())->toBe(360);
    expect(DB::table('plan_accruals')->where('savings_plan_id', $compound->id)->count())->toBe(360);
    expect(DB::table('plan_capitalizations')->where('savings_plan_id', $compound->id)->count())->toBe(11);
    expect($near->matures_at->diffInDays(app(Clock::class)->now(), absolute: true))->toBeGreaterThan(15);
    expect(DB::table('ledger_entries')->select('journal_id')->groupBy('journal_id')->havingRaw('SUM(amount) != 0')->count())->toBe(0);
    expect(app(Ledger::class)->projectionMismatches())->toBe([]);
    expect(app(AuditTrail::class)->verify())->toBeGreaterThan(0);
    expect(DB::table('activity_log')->where('event', 'product.published')->count())->toBe(3);
    expect(DB::table('activity_log')->where('event', 'simulation.created')->count())->toBe(1);
    $dates = DB::table('activity_log')->orderBy('id')->pluck('created_at')->all();
    $sorted = $dates;
    sort($sorted);
    expect($dates)->toBe($sorted);
    expect(Hash::check('demo-test-password', User::where('email', 'demo@example.test')->sole()->password))->toBeTrue();
    expect(DB::table('payment_notices')->whereNull('mail_sent_at')->count())->toBe(0);
    expect(DB::table('savings_notices')->whereNull('mail_sent_at')->count())->toBe(0);
    $journals = DB::table('journals')->count();
    $accruals = DB::table('plan_accruals')->count();
    $events = DB::table('activity_log')->count();
    $this->seed(DemoHistorySeeder::class);
    expect(DB::table('journals')->count())->toBe($journals);
    expect(DB::table('plan_accruals')->count())->toBe($accruals);
    expect(DB::table('activity_log')->count())->toBe($events);
    expect(app(AuditTrail::class)->verify())->toBe($events);
    Http::assertNothingSent();
    Notification::assertNothingSent();
});

test('history is resumable after an interrupted advance without duplicate funding', function (): void {
    Http::preventStrayRequests();
    Notification::fake();
    payoutStaff('admin');
    $interrupted = false;
    Event::listen(JobProcessing::class, function (JobProcessing $event) use (&$interrupted): void {
        if (! $interrupted && $event->job->resolveName() === ProcessSavingsPlan::class) {
            $interrupted = true;
            throw new RuntimeException('Interrupted history processing');
        }
    });
    $history = app(DemoHistory::class);
    expect(fn () => $history->seed())->toThrow(DomainException::class);
    expect(DB::table('demo_state')->sole()->seeded_at)->toBeNull();
    expect(DB::table('demo_state')->sole()->history_step)->toBe(1);
    $history->seed();

    expect(app(DemoTimeMachine::class)->state()['seeded_at'])->not->toBeNull();
    expect(DB::table('deposits')->count())->toBe(1);
    expect(DB::table('journals')->where('operation_key', 'like', 'plan-funding:%')->count())->toBe(SavingsPlan::whereNotNull('funding_journal_id')->count());
    expect(app(Ledger::class)->projectionMismatches())->toBe([]);
    expect(app(AuditTrail::class)->verify())->toBeGreaterThan(0);
    Http::assertNothingSent();
    Notification::assertNothingSent();
});

test('history refuses existing customers without deleting data', function (): void {
    payoutStaff('admin');
    $user = User::factory()->create();

    expect(fn () => app(DemoHistory::class)->seed())->toThrow(DomainException::class, 'empty customer');
    $this->assertModelExists($user);
    $this->assertDatabaseCount('savings_products', 0);
});

test('history cannot run outside demo', function (string $environment): void {
    app()->instance('env', $environment);

    expect(fn () => app(DemoHistory::class)->seed())->toThrow(AuthorizationException::class);
    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('demo_state', 0);
})->with(['local', 'testing', 'production']);

test('history refuses a moved initial clock before creating any fixtures', function (): void {
    $admin = payoutStaff('admin');
    $machine = app(DemoTimeMachine::class);
    $machine->start($admin, 90, app(Clock::class)->now()->toIso8601String());
    while ($machine->state()['target_at'] !== null) {
        $machine->process();
    }
    $before = app(Clock::class)->now();

    expect(fn () => app(DemoHistory::class)->seed())->toThrow(DomainException::class, 'before advancing');
    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('savings_products', 0);
    $this->assertDatabaseCount('journals', 0);
    expect(app(Clock::class)->now()->equalTo($before))->toBeTrue();
    expect($machine->state()['history_step'])->toBe(0);
});

test('an unfinished history stage reserves time advances until seeding completes', function (): void {
    $admin = payoutStaff('admin');
    $machine = app(DemoTimeMachine::class);
    $instant = app(Clock::class)->now()->toIso8601String();
    DB::table('demo_state')->where('id', 1)->update(['history_step' => 1]);

    expect(fn () => $machine->start($admin, 90, $instant))->toThrow(DomainException::class, 'resume history');
    expect($machine->state()['target_at'])->toBeNull();
    expect(app(Clock::class)->now()->toIso8601String())->toBe($instant);
});
