<?php

use App\Contracts\Clock;
use App\Models\AuditEvent;
use App\Models\User;
use App\Support\AuditTrail;
use App\Support\FrozenClock;
use App\Support\Ledger;
use App\Support\Wallets;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    app()->instance(Clock::class, new FrozenClock(CarbonImmutable::parse('2026-11-07T10:00:00Z')));
});

test('new journals and their audit evidence commit together and replay produces no new evidence', function (): void {
    $user = User::factory()->create();

    $journal = postWalletTestCredit($user);
    postWalletTestCredit($user);

    $event = AuditEvent::sole();
    expect($event->subject_id)->toBe($journal->id);
    expect($event->properties['entries'])->toHaveCount(2);
    expect(app(AuditTrail::class)->verify())->toBe(1);
});

test('missing audit keys roll back journal entries and projections', function (): void {
    $user = User::factory()->create();
    app(Wallets::class)->provision($user);
    config(['audit.keys.v1' => null]);

    expect(fn () => postWalletTestCredit($user))->toThrow(DomainException::class, 'dedicated audit key');

    $this->assertDatabaseCount('journals', 0);
    $this->assertDatabaseCount('ledger_entries', 0);
    $this->assertDatabaseCount('activity_log', 0);
    expect(app(Ledger::class)->projectionMismatches())->toBe([]);
});

test('protected audit capture redacts secrets and masks bank details before hashing', function (): void {
    $event = app(AuditTrail::class)->record('destination.saved', properties: ['account_number' => '0123456789',
        'nested' => ['password' => 'password-fixture', 'authorization_code' => 'authorization-fixture', 'reference' => 'reference-fixture']]);

    expect($event->properties['account_number'])->toBe('******6789');
    expect($event->properties['nested'])->toBe(['password' => '[redacted]', 'authorization_code' => '[redacted]', 'reference' => 'reference-fixture']);
    expect(app(AuditTrail::class)->verify())->toBe(1);
});

test('audit history rejects direct updates deletes and replacements', function (string $operation): void {
    $event = app(AuditTrail::class)->record('fixture');

    expect(fn () => match ($operation) {
        'update' => DB::table('activity_log')->where('id', $event->id)->update(['description' => 'changed']),
        'delete' => DB::table('activity_log')->where('id', $event->id)->delete(),
        'replace' => DB::table('activity_log')->insertOrIgnore($event->getAttributes()),
    })->toThrow(QueryException::class, 'immutable');

    expect(app(AuditTrail::class)->verify())->toBe(1);
})->with(['update', 'delete', 'replace']);

test('chain verification detects tampering after database protections are bypassed', function (): void {
    app(AuditTrail::class)->record('fixture');
    DB::unprepared('DROP TRIGGER activity_log_no_update');
    DB::table('activity_log')->update(['description' => 'tampered']);

    expect(fn () => app(AuditTrail::class)->verify())->toThrow(DomainException::class, 'event 1');
});

test('customer closure preserves identities with only an audit reference', function (): void {
    $user = User::factory()->create();
    app(Wallets::class)->provision($user);
    app(AuditTrail::class)->record('customer.security', $user);

    app(Wallets::class)->close($user);

    expect($user->fresh()->is_active)->toBeFalse();
    $this->assertModelExists($user);
    expect(app(AuditTrail::class)->verify())->toBe(3);
});

test('package activity calls use the same immediate protected chain writer', function (): void {
    activity()->event('package.fixture')->withProperties(['secret' => 'fixture'])->log('package.fixture');

    expect(AuditEvent::sole()->properties['secret'])->toBe('[redacted]');
    expect(app(AuditTrail::class)->verify())->toBe(1);
});
