<?php

use App\Contracts\Clock;
use App\Models\LedgerAccount;
use App\Models\SavingsPlan;
use App\Models\SavingsProductVersion;
use App\Models\User;
use App\Support\Clock\FrozenClock;
use App\Support\Ledger\Ledger;
use App\Support\Ledger\Wallets;
use App\Support\Money\Money;
use App\Support\Savings\PlanStates\Active;
use App\Support\Savings\PlanStates\Cancelled;
use App\Support\Savings\PlanStates\Closed;
use App\Support\Savings\PlanStates\Draft;
use App\Support\Savings\PlanStates\Matured;
use App\Support\Savings\PlanStates\PendingFunding;
use App\Support\Savings\PlanStates\Suspended;
use App\Support\Savings\SavingsPlans;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['inertia.ssr.enabled' => false]);
    app()->instance(Clock::class, new FrozenClock(CarbonImmutable::parse('2026-11-07T10:00:00Z')));
});

test('persisted savings aliases hydrate their lifecycle states', function (string $alias, string $class) {
    $plan = SavingsPlan::factory()->make();
    $plan->setRawAttributes(['state' => $alias], sync: true);

    expect($plan->state)->toBeInstanceOf($class);
    expect((string) $plan->state)->toBe($alias);
})->with([
    ['draft', Draft::class],
    ['pending_funding', PendingFunding::class],
    ['active', Active::class],
    ['suspended', Suspended::class],
    ['matured', Matured::class],
    ['cancelled', Cancelled::class],
    ['closed', Closed::class],
]);

test('a customer reviews accepts and funds their snapshotted contract once', function () {
    $user = User::factory()->create();
    postWalletTestCredit($user, kobo: 2000000);
    $version = SavingsProductVersion::factory()->create();
    $key = (string) Str::uuid();
    $this->actingAs($user)->post(route('savings.store'), ['version_id' => $version->id, 'days' => 30, 'amount' => '10000.00', 'request_key' => $key])->assertRedirect();
    $plan = SavingsPlan::sole();
    $this->get(route('savings.show', $plan))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Savings/Show')->where('plan.contract.principal', '1000000'));
    $this->patch(route('savings.update', $plan), ['action' => 'accept'])->assertRedirect();
    $this->patch(route('savings.update', $plan), ['action' => 'fund'])->assertRedirect();
    $this->patch(route('savings.update', $plan), ['action' => 'fund'])->assertRedirect();

    expect((string) $plan->fresh()->state)->toBe('active');
    expect($plan->fresh()->accrual_starts_at->toIso8601String())->toBe('2026-11-08T00:00:00+00:00');
    expect($plan->fresh()->matures_at->toIso8601String())->toBe('2026-12-08T00:00:00+00:00');
    expect(LedgerAccount::where('code', 'customer:'.$user->id.':wallet')->sole()->balance->kobo)->toBe(-1000000);
    $this->assertDatabaseCount('journals', 2);
    expect(app(Ledger::class)->projectionMismatches())->toBe([]);
});

test('funding uses the configured application calendar near midnight', function (string $timezone, string $effectiveDate, string $startsAt, string $maturesAt) {
    config(['app.timezone' => $timezone]);
    app(Clock::class)->set(CarbonImmutable::parse('2026-11-07T23:30:00Z'));
    $plan = SavingsPlan::factory()->create();
    postWalletTestCredit($plan->user, kobo: 2000000);
    $plans = app(SavingsPlans::class);
    $plans->accept($plan->user, $plan);

    $funded = $plans->fund($plan->user, $plan)->fresh();

    expect($funded->accrual_starts_at->toIso8601String())->toBe($startsAt);
    expect($funded->matures_at->toIso8601String())->toBe($maturesAt);
    $this->assertDatabaseHas('journals', ['id' => $funded->funding_journal_id, 'effective_date' => $effectiveDate]);
})->with([
    'default UTC' => ['UTC', '2026-11-07', '2026-11-08T00:00:00+00:00', '2026-12-08T00:00:00+00:00'],
    'configured timezone' => ['Asia/Kolkata', '2026-11-08', '2026-11-08T18:30:00+00:00', '2026-12-08T18:30:00+00:00'],
]);

test('funding rejects invalid lifecycle insufficient funds recovery and inactive identities atomically', function (string $failure) {
    $plan = SavingsPlan::factory()->create();
    $user = $plan->user;
    postWalletTestCredit($user, kobo: $failure === 'insufficient' ? 999999 : 2000000);
    $plans = app(SavingsPlans::class);
    if ($failure !== 'draft') {
        $plans->accept($user, $plan);
    }
    if ($failure === 'inactive') {
        $user->forceFill(['is_active' => false])->save();
    }
    if ($failure === 'unverified') {
        $user->forceFill(['email_verified_at' => null])->save();
    }
    if ($failure === 'recovery') {
        $debt = LedgerAccount::where('code', 'customer:'.$user->id.':reversal_receivable')->sole();
        app(Ledger::class)->post('debt', [['account_id' => $debt->id, 'amount' => new Money(1)],
            ['account_id' => LedgerAccount::where('code', 'house:cash_at_gateway')->sole()->id, 'amount' => new Money(-1)]], '2026-11-07', 'Test debt', $user->id);
    }
    $before = LedgerAccount::count();

    expect(fn () => $plans->fund($user, $plan))->toThrow(DomainException::class);
    expect($plan->fresh()->funding_journal_id)->toBeNull();
    expect(LedgerAccount::count())->toBe($before);
    expect(app(Ledger::class)->projectionMismatches())->toBe([]);
})->with(['draft', 'insufficient', 'recovery', 'inactive', 'unverified']);

test('deadline rejects late funding before a sweep and never posts money', function () {
    $plan = SavingsPlan::factory()->create();
    postWalletTestCredit($plan->user, kobo: 2000000);
    $plans = app(SavingsPlans::class);
    $plans->accept($plan->user, $plan);
    app(Clock::class)->set($plan->funding_deadline);

    $expired = $plans->fund($plan->user, $plan);

    expect((string) $expired->state)->toBe('cancelled');
    expect($expired->closure_reason)->toBe('funding_expired');
    $this->assertDatabaseCount('journals', 1);
});

test('savings endpoints isolate owners and reject customer and staff guard confusion', function () {
    $plan = SavingsPlan::factory()->create();
    $this->get(route('savings.index'))->assertRedirect(route('login'));
    $other = User::factory()->create();
    app(Wallets::class)->provision($other);
    $this->actingAs($other)->get(route('savings.show', $plan))->assertNotFound();
    $this->patch(route('savings.update', $plan), ['action' => 'fund'])->assertNotFound();
    $this->actingAs(User::factory()->unverified()->create())->get(route('savings.index'))->assertRedirect(route('verification.notice'));
    expect((string) $plan->fresh()->state)->toBe('draft');
});

test('unfunded savings references survive account deactivation', function () {
    $plan = SavingsPlan::factory()->create();
    app(Wallets::class)->provision($plan->user);

    app(Wallets::class)->close($plan->user);

    expect($plan->user->fresh()->is_active)->toBeFalse();
    $this->assertModelExists($plan);
});

test('draft retries retain the original contract and reject changed requests', function () {
    $user = User::factory()->create();
    $version = SavingsProductVersion::factory()->create();
    $key = (string) Str::uuid();
    $plans = app(SavingsPlans::class);
    $first = $plans->draft($user, $version, new Money(1000000), 30, $key);

    expect($plans->draft($user, $version, new Money(1000000), 30, $key)->id)->toBe($first->id);
    expect(fn () => $plans->draft($user, $version, new Money(1000001), 30, $key))->toThrow(DomainException::class);
    $this->assertDatabaseCount('savings_plans', 1);
});

test('invalid savings input cannot create a contract', function (array $changes, string $field) {
    $user = User::factory()->create();
    $version = SavingsProductVersion::factory()->create();

    $this->actingAs($user)->post(route('savings.store'), array_replace(['version_id' => $version->id,
        'days' => 30, 'amount' => '10000.00', 'request_key' => (string) Str::uuid()], $changes))->assertSessionHasErrors($field);

    $this->assertDatabaseCount('savings_plans', 0);
})->with([[['days' => 31], 'days'], [['amount' => '1e6'], 'amount'], [['amount' => '1000000000000000000000'], 'amount'], [['request_key' => 'invalid'], 'request_key']]);

test('a product permitting one kobo accepts a fractional naira principal', function () {
    $user = User::factory()->create();
    $version = SavingsProductVersion::factory()->create();

    $this->actingAs($user)->post(route('savings.store'), ['version_id' => $version->id, 'days' => 30,
        'amount' => '0.01', 'request_key' => (string) Str::uuid()])->assertRedirect();

    expect(SavingsPlan::sole()->contract['principal'])->toBe('1');
});

test('terms beyond the supported calendar cannot lock customer funds', function () {
    $version = SavingsProductVersion::factory()->make();
    $contract = $version->contract + ['principal' => '1', 'days' => 9223372036854775800, 'annual_rate_bp' => 0];
    $plan = SavingsPlan::factory()->create(['contract' => $contract]);
    postWalletTestCredit($plan->user, kobo: 100);
    app(SavingsPlans::class)->accept($plan->user, $plan);

    expect(fn () => app(SavingsPlans::class)->fund($plan->user, $plan))->toThrow(DomainException::class, 'supported calendar');

    expect($plan->fresh()->funding_journal_id)->toBeNull();
    $this->assertDatabaseCount('journals', 1);
});

test('contract rates larger than JavaScript safe integers travel as exact decimal strings', function () {
    $user = User::factory()->create();
    app(Wallets::class)->provision($user);
    $contract = SavingsProductVersion::factory()->make()->contract;
    $contract['terms'][0]['annual_rate_bp'] = PHP_INT_MAX;
    $version = SavingsProductVersion::factory()->create(['contract' => $contract]);
    $plan = app(SavingsPlans::class)->draft($user, $version, new Money(1), 30, (string) Str::uuid());

    $this->actingAs($user)->get(route('savings.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('products.0.contract.terms.0.annual_rate_bp', '9223372036854775807'));
    $this->get(route('savings.show', $plan))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('plan.contract.annual_rate_bp', '9223372036854775807'));
});
