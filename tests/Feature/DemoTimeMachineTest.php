<?php

use App\Contracts\Clock;
use App\Filament\Pages\TimeMachine;
use App\Filament\Resources\PaymentSimulations\PaymentSimulationResource;
use App\Jobs\ScanPayments;
use App\Models\Deposit;
use App\Models\PaymentException;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Support\Clock\DemoClock;
use App\Support\Clock\SystemClock;
use App\Support\Demo\DemoTimeMachine;
use App\Support\Ledger\Ledger;
use App\Support\Money\Money;
use App\Support\Payments\GatewayFailure;
use App\Support\Payments\PaymentSimulator;
use App\Support\Payments\PaystackGateway;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function (): void {
    app()->instance('env', 'demo');
    app()->forgetInstance(Clock::class);
    config(['demo.start_at' => '2025-01-01T23:05:00Z', 'demo.chunk_seconds' => 10,
        'withdrawals.simulation_enabled' => true, 'withdrawals.simulation_signing_key' => str_repeat('s', 32)]);
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

test('the demo clock persists UTC time and existing instances observe new instants', function (): void {
    $clock = app(Clock::class);
    expect($clock)->toBeInstanceOf(DemoClock::class);
    expect($clock->now()->toIso8601String())->toBe('2025-01-01T23:05:00+00:00');
    DB::table('demo_state')->where('id', 1)->update(['current_at' => '2025-02-01 00:00:00']);

    expect($clock->now()->toIso8601String())->toBe('2025-02-01T00:00:00+00:00');
    expect((new DemoClock)->now()->toIso8601String())->toBe('2025-02-01T00:00:00+00:00');
});

test('demo features refuse every environment except demo and preserve the system clock', function (string $environment): void {
    app()->instance('env', $environment);
    app()->forgetInstance(Clock::class);
    $admin = payoutStaff('admin');

    expect(app(Clock::class))->toBeInstanceOf(SystemClock::class);
    expect(fn () => app(DemoTimeMachine::class)->start($admin, 1, '2025-01-01T23:05:00Z'))->toThrow(AuthorizationException::class);
    expect(fn () => (new DemoClock)->now())->toThrow(DomainException::class);
    $this->assertDatabaseCount('demo_state', 0);
    Filament::setCurrentPanel('admin');
    $this->actingAs($admin, 'staff')->get('/admin/time-machine')->assertForbidden();
})->with(['local', 'testing', 'production', 'staging']);

test('managers and inactive administrators cannot advance demo time', function (string $role, bool $active): void {
    $staff = payoutStaff($role);
    $staff->is_active = $active;
    $staff->save();
    $clock = app(Clock::class);

    expect(fn () => app(DemoTimeMachine::class)->start($staff, 1, $clock->now()->toIso8601String()))->toThrow(AuthorizationException::class);
    expect($clock->now()->toIso8601String())->toBe('2025-01-01T23:05:00+00:00');
})->with([['manager', true], ['admin', false]]);

test('the admin page advances a funded plan through real jobs and credits maturity once', function (): void {
    Http::preventStrayRequests();
    Notification::fake();
    $admin = payoutStaff('admin');
    $plan = fundedAccrualPlan();
    Filament::setCurrentPanel('admin');
    $this->actingAs($admin, 'staff');

    $page = Livewire::test(TimeMachine::class)
        ->callAction(TestAction::make('advance'), ['days' => 31])
        ->assertHasNoActionErrors();
    while (app(DemoTimeMachine::class)->state()['target_at'] !== null) {
        $page->call('process');
    }
    $page->assertSee('Ready');
    expect((string) $plan->fresh()->state)->toBe('closed');
    $this->assertDatabaseCount('plan_accruals', 30);
    $this->assertDatabaseCount('plan_settlements', 1);
    $this->assertDatabaseCount('notifications', 1);
    expect(app(Ledger::class)->projectionMismatches())->toBe([]);
    expect(DB::table('plan_settlements')->sole()->wallet_credit)->toBe(1007397);
    $page->call('process');
    $this->assertDatabaseCount('plan_settlements', 1);
    Http::assertNothingSent();
    Notification::assertNothingSent();
});

test('customer sessions cannot access the time machine', function (): void {
    $this->actingAs(User::factory()->create(), 'web')->get('/admin/time-machine')
        ->assertRedirect(route('filament.admin.auth.login'));
});

test('an administrator without simulator permission cannot use direct time actions', function (): void {
    $admin = payoutStaff('admin');
    $admin->roles()->detach();
    $admin->assignRole('manager');
    $admin->givePermissionTo('products.manage');
    $now = app(Clock::class)->now()->toIso8601String();

    expect(fn () => app(DemoTimeMachine::class)->start($admin, 1, $now))->toThrow(AuthorizationException::class);
    $this->assertDatabaseMissing('demo_state', ['id' => 1, 'target_at' => '2025-01-02 23:05:00']);
});

test('invalid and duplicate requests cannot advance time or overwrite a pending target', function (): void {
    $admin = payoutStaff('admin');
    $machine = app(DemoTimeMachine::class);
    $now = app(Clock::class)->now()->toIso8601String();
    expect(fn () => $machine->start($admin, 0, $now))->toThrow(DomainException::class);
    expect(fn () => $machine->start($admin, 366, $now))->toThrow(DomainException::class);
    $machine->start($admin, 1, $now);
    expect(fn () => $machine->start($admin, 1, $now))->toThrow(DomainException::class, 'pending');
    $machine->process();
    expect(fn () => $machine->start($admin, 1, $now))->toThrow(DomainException::class, 'changed');
    expect(app(Clock::class)->now()->toIso8601String())->toBe('2025-01-02T23:05:00+00:00');
});

test('overlapping processing cannot move the clock', function (): void {
    $admin = payoutStaff('admin');
    $machine = app(DemoTimeMachine::class);
    $machine->start($admin, 1, app(Clock::class)->now()->toIso8601String());
    $lock = Cache::store(config('ledger.lock_store'))->lock('demo:time-machine', 600);
    $lock->get();
    try {
        expect(fn () => $machine->process())->toThrow(DomainException::class, 'already running');
        expect(app(Clock::class)->now()->toIso8601String())->toBe('2025-01-01T23:05:00+00:00');
    } finally {
        $lock->release();
    }
});

test('due processing failures retain the target and resume without duplicate accruals', function (): void {
    Notification::fake();
    $admin = payoutStaff('admin');
    $plan = fundedAccrualPlan();
    $machine = app(DemoTimeMachine::class);
    $machine->start($admin, 31, app(Clock::class)->now()->toIso8601String());
    config(['demo.maximum_passes' => 0]);

    expect(fn () => $machine->process())->toThrow(DomainException::class, 'processing limit');
    expect($machine->state()['failure'])->not->toBeNull();
    expect($machine->state()['target_at'])->toBe('2025-02-01 23:05:00');
    config(['demo.maximum_passes' => 100]);
    $machine->resume($admin);
    while ($machine->state()['target_at'] !== null) {
        $machine->process();
    }
    expect((string) $plan->fresh()->state)->toBe('closed');
    $this->assertDatabaseCount('plan_accruals', 30);
    $this->assertDatabaseCount('plan_settlements', 1);
    Notification::assertNothingSent();
});

test('demo payments use the simulator and old provider scans never reach Paystack', function (): void {
    Http::preventStrayRequests();
    Notification::fake();
    $admin = payoutStaff('admin');
    $user = User::factory()->create();
    Filament::setCurrentPanel('admin');
    $this->actingAs($admin, 'staff');
    expect(PaymentSimulationResource::canViewAny())->toBeTrue();
    $simulation = app(PaymentSimulator::class)->create($admin, $user, 'deposit', new Money(100000));
    app(PaymentSimulator::class)->observe($admin, $simulation, 'success');
    ScanPayments::dispatch('transaction');

    expect((string) Deposit::findOrFail($simulation->subject_id)->state)->toBe('confirmed');
    $this->assertDatabaseCount('notifications', 1);
    expect(DB::table('payment_notices')->whereNull('mail_sent_at')->count())->toBe(0);
    expect(fn () => app(PaystackGateway::class)->list('transaction', []))->toThrow(GatewayFailure::class, 'disabled in demo');
    Http::assertNothingSent();
    Notification::assertNothingSent();
});

test('real financial records prevent demo advances before any time changes', function (string $kind): void {
    $admin = payoutStaff('admin');
    $now = app(Clock::class)->now()->toIso8601String();
    match ($kind) {
        'deposit' => Deposit::factory()->create(),
        'withdrawal' => WithdrawalRequest::factory()->create(),
        'investigation' => PaymentException::record('demo-preflight', 'Fixture', app(Clock::class)->now(), resourceType: 'dispute', resourceId: '123'),
    };
    if ($kind === 'investigation') {
        DB::table('payment_exceptions')->update(['provider_open' => true]);
    }

    expect(fn () => app(DemoTimeMachine::class)->start($admin, 1, $now))->toThrow(DomainException::class);
    expect(app(Clock::class)->now()->toIso8601String())->toBe($now);
    expect(app(DemoTimeMachine::class)->state()['target_at'])->toBeNull();
})->with(['deposit', 'withdrawal', 'investigation']);

test('time advancement expires review work at its scheduled minute', function (): void {
    Http::preventStrayRequests();
    Notification::fake();
    $admin = payoutStaff('admin');
    // Initialize the persisted clock before choosing a non-boundary request instant.
    app(Clock::class)->now();
    DB::table('demo_state')->where('id', 1)->update(['current_at' => '2025-01-02 11:10:30']);
    $user = User::factory()->create();
    postWalletTestCredit($user, kobo: 101000);
    $simulation = app(PaymentSimulator::class)->create($admin, $user, 'withdrawal', new Money(100000));
    $withdrawal = WithdrawalRequest::findOrFail($simulation->subject_id);
    $machine = app(DemoTimeMachine::class);
    $machine->start($admin, 2, app(Clock::class)->now()->toIso8601String());
    while ($machine->state()['target_at'] !== null) {
        $machine->process();
    }

    expect((string) $withdrawal->fresh()->state)->toBe('cancelled');
    expect($withdrawal->fresh()->submitted_at)->toBeNull();
    $release = DB::table('journals')->where('operation_key', 'withdrawal:'.$withdrawal->id.':release')->sole();
    expect($release)->not->toBeNull();
    expect(CarbonImmutable::parse($release->posted_at, 'UTC')->toIso8601String())->toBe('2025-01-03T11:11:00+00:00');
    expect(app(Ledger::class)->projectionMismatches())->toBe([]);
    Http::assertNothingSent();
    Notification::assertNothingSent();
});
