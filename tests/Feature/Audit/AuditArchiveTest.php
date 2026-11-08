<?php

use App\Contracts\Clock;
use App\Models\AuditEvent;
use App\Support\Audit\AuditArchives;
use App\Support\Audit\AuditTrail;
use App\Support\Clock\FrozenClock;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

function enableAuditMaintenance(): void
{
    app()->instance(MaintenanceMode::class, Mockery::mock(MaintenanceMode::class, function ($mock): void {
        $mock->shouldReceive('active')->andReturn(true);
    }));
}

test('verified encrypted prefixes can be restored and online chaining continues across archives', function (): void {
    Storage::fake('local');
    enableAuditMaintenance();
    app()->instance(Clock::class, new FrozenClock(CarbonImmutable::parse('2025-01-01T00:00:00Z')));
    app(AuditTrail::class)->record('old.fixture');
    app()->instance(Clock::class, new FrozenClock(CarbonImmutable::parse('2026-11-07T00:00:00Z')));
    app()->forgetScopedInstances();
    app(AuditTrail::class)->record('current.fixture');

    expect(app(AuditArchives::class)->maintain())->toBe(1);
    app(AuditTrail::class)->record('after.archive');

    $manifest = (array) DB::table('audit_archives')->sole();
    $restored = app(AuditArchives::class)->restore($manifest);
    expect($restored[0]['event'])->toBe('old.fixture');
    expect(Storage::disk('local')->get($manifest['path']))->not->toContain('old.fixture');
    expect(AuditEvent::count())->toBe(2);
    expect(app(AuditTrail::class)->verify())->toBe(3);
});

test('archive removal requires maintenance and leaves evidence unchanged otherwise', function (): void {
    app(AuditTrail::class)->record('fixture');

    expect(fn () => app(AuditArchives::class)->maintain())->toThrow(DomainException::class, 'maintenance');

    expect(AuditEvent::count())->toBe(1);
    expect(app(AuditTrail::class)->verify())->toBe(1);
});

test('expired archives retain signed boundaries while maintenance removes their encrypted files', function (): void {
    Storage::fake('local');
    enableAuditMaintenance();
    app()->instance(Clock::class, new FrozenClock(CarbonImmutable::parse('2018-01-01T00:00:00Z')));
    app(AuditTrail::class)->record('expired.fixture');
    app()->instance(Clock::class, new FrozenClock(CarbonImmutable::parse('2026-11-07T00:00:00Z')));
    app()->forgetScopedInstances();

    app(AuditArchives::class)->maintain();
    app(AuditArchives::class)->purgeFiles();

    $manifest = DB::table('audit_archives')->sole();
    expect($manifest->purged_at)->not->toBeNull();
    Storage::disk('local')->assertMissing($manifest->path);
    expect(app(AuditTrail::class)->verify())->toBe(1);
});

test('missing or altered archive evidence fails verification', function (): void {
    Storage::fake('local');
    enableAuditMaintenance();
    app()->instance(Clock::class, new FrozenClock(CarbonImmutable::parse('2024-01-01T00:00:00Z')));
    app(AuditTrail::class)->record('fixture');
    app()->instance(Clock::class, new FrozenClock(CarbonImmutable::parse('2026-11-07T00:00:00Z')));
    app()->forgetScopedInstances();
    app(AuditArchives::class)->maintain();
    Storage::disk('local')->put(DB::table('audit_archives')->sole()->path, 'altered');

    expect(fn () => app(AuditTrail::class)->verify())->toThrow(DomainException::class, 'missing or altered');
});

test('interrupted archive removal retains online evidence and can resume without a broken boundary', function (): void {
    Storage::fake('local');
    enableAuditMaintenance();
    app()->instance(Clock::class, new FrozenClock(CarbonImmutable::parse('2024-01-01T00:00:00Z')));
    app(AuditTrail::class)->record('retained.fixture');
    app()->instance(Clock::class, new FrozenClock(CarbonImmutable::parse('2026-11-07T00:00:00Z')));
    app()->forgetScopedInstances();
    DB::unprepared("CREATE TRIGGER interrupt_archive BEFORE DELETE ON activity_log BEGIN SELECT RAISE(ABORT, 'interrupted maintenance'); END");

    expect(fn () => app(AuditArchives::class)->maintain())->toThrow(QueryException::class, 'interrupted maintenance');

    $this->assertDatabaseCount('activity_log', 1);
    $this->assertDatabaseCount('audit_archives', 0);
    expect(DB::table('audit_head')->sole()->floor_sequence)->toBe(0);
    expect(app(AuditTrail::class)->verify())->toBe(1);
    DB::unprepared('DROP TRIGGER interrupt_archive');
    expect(app(AuditArchives::class)->maintain())->toBe(1);
    expect(app(AuditTrail::class)->verify())->toBe(1);
});
