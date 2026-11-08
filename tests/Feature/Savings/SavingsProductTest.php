<?php

use App\Contracts\Clock;
use App\Filament\Resources\SavingsProducts\Pages\ManageSavingsProducts;
use App\Models\LedgerAccount;
use App\Models\SavingsNotice;
use App\Models\SavingsPlan;
use App\Models\SavingsProduct;
use App\Models\SavingsProductVersion;
use App\Models\Staff;
use App\Models\User;
use App\Support\Approvals;
use App\Support\FrozenClock;
use App\Support\Ledger;
use App\Support\Money;
use App\Support\SavingsPlans;
use App\Support\SavingsProducts;
use Carbon\CarbonImmutable;
use Database\Seeders\StaffPermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    app()->instance(Clock::class, new FrozenClock(CarbonImmutable::parse('2026-11-07T10:00:00Z')));
});

function savingsProductData(): array
{
    return ['name' => 'Configured savings', 'disclosure' => 'Fixture financial terms.', 'minimum' => '100', 'maximum' => '100000000',
        'withholding_bp' => 1000, 'terms' => [['days' => 30, 'annual_rate_bp' => 1000], ['days' => 60, 'annual_rate_bp' => 1200]]];
}

function savingsAdmin(): Staff
{
    app(StaffPermissionSeeder::class)->run();
    $staff = Staff::factory()->withTwoFactor()->create();
    $staff->assignRole('admin');

    return $staff;
}

test('admin publication appends versions while existing contracts retain their exact terms', function () {
    $admin = savingsAdmin();
    $products = app(SavingsProducts::class);
    $product = $products->publish($admin, savingsProductData());
    $version = $product->versions()->sole();
    $user = User::factory()->create();
    $plan = app(SavingsPlans::class)->draft($user, $version, new Money(1000000), 30, (string) Str::uuid());
    $changed = savingsProductData();
    $changed['terms'][0]['annual_rate_bp'] = 1500;

    $approval = app(Approvals::class)->propose($admin, 'product.rate', $product, ['data' => $changed], 'Change offered rate');
    expect($product->fresh()->current_version)->toBe(1);
    app(Approvals::class)->decide(savingsAdmin(), $approval, true, 'Checked offered rate');

    expect($product->fresh()->current_version)->toBe(2);
    expect($version->fresh()->contract['terms'][0]['annual_rate_bp'])->toBe(1000);
    expect($plan->fresh()->contract['annual_rate_bp'])->toBe(1000);
    expect(fn () => app(SavingsPlans::class)->draft($user, $version, new Money(1000000), 30, (string) Str::uuid()))->toThrow(DomainException::class, 'no longer offered');
    $this->assertDatabaseCount('savings_product_versions', 2);
});

test('native Filament publication creates and versions products through permission controlled actions', function () {
    $this->actingAs(savingsAdmin(), 'staff');
    Filament::setCurrentPanel('admin');
    Livewire::test(ManageSavingsProducts::class)->callAction('create', data: savingsProductData())->assertHasNoFormErrors();
    $product = SavingsProduct::sole();

    Livewire::test(ManageSavingsProducts::class)->callAction(TestAction::make('edit')->table($product), data: savingsProductData())->assertHasNoFormErrors();

    expect($product->fresh()->current_version)->toBe(2);
    $this->assertDatabaseCount('savings_product_versions', 2);
});

test('product permissions reject managers inactive admins and removed permissions', function (string $case) {
    $staff = savingsAdmin();
    if ($case === 'manager') {
        $staff->syncRoles(['manager']);
    } elseif ($case === 'inactive') {
        $staff->forceFill(['is_active' => false])->save();
    } else {
        Role::findByName('admin', 'staff')->revokePermissionTo('products.manage');
    }

    expect(Gate::forUser($staff)->allows('create', SavingsProduct::class))->toBeFalse();
    expect(fn () => app(SavingsProducts::class)->publish($staff, savingsProductData()))->toThrow(AuthorizationException::class);
    $this->assertDatabaseCount('savings_products', 0);
})->with(['manager', 'inactive', 'permission']);

test('invalid product terms cannot publish a partial product', function (array $changes) {
    $admin = savingsAdmin();

    expect(fn () => app(SavingsProducts::class)->publish($admin, array_replace(savingsProductData(), $changes)))->toThrow(ValidationException::class);

    $this->assertDatabaseCount('savings_products', 0);
    $this->assertDatabaseCount('savings_product_versions', 0);
})->with([
    [['terms' => [['days' => 31, 'annual_rate_bp' => 1000]]]],
    [['terms' => [['days' => 30, 'annual_rate_bp' => -1]]]],
    [['terms' => [['days' => 30, 'annual_rate_bp' => 1], ['days' => 30, 'annual_rate_bp' => 2]]]],
    [['maximum' => '99']], [['maximum' => '99999999999999999999999999']], [['withholding_bp' => 10001]],
]);

test('contract and published version history reject direct database changes', function (string $target) {
    $plan = SavingsPlan::factory()->create();
    $version = SavingsProductVersion::findOrFail($plan->savings_product_version_id);

    expect(function () use ($plan, $version, $target) {
        match ($target) {
            'snapshot' => DB::table('savings_plans')->where('id', $plan->id)->update(['contract' => '{}']),
            'deadline' => DB::table('savings_plans')->where('id', $plan->id)->update(['funding_deadline' => '2030-01-01']),
            'version-update' => DB::table('savings_product_versions')->where('id', $version->id)->update(['contract' => '{}']),
            'version-delete' => DB::table('savings_product_versions')->where('id', $version->id)->delete(),
        };
    })->toThrow(QueryException::class, 'immutable');
})->with(['snapshot', 'deadline', 'version-update', 'version-delete']);

test('new product variants validate their interest lock holding period and deduction contracts', function (array $changes, string $field): void {
    $data = array_replace(savingsProductData(), ['interest' => 'daily', 'lock' => 'terminable', 'minimum_holding_days' => 7,
        'forfeiture_bp' => 5000, 'principal_penalty_bp' => 100], $changes);
    try {
        app(SavingsProducts::class)->publish(savingsAdmin(), $data);
        test()->fail('Invalid contract was published.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
    }
    $this->assertDatabaseCount('savings_products', 0);
})->with([
    [['interest' => 'monthly'], 'interest'], [['lock' => 'flexible'], 'lock'],
    [['minimum_holding_days' => null], 'minimum_holding_days'], [['minimum_holding_days' => 0], 'minimum_holding_days'],
    [['minimum_holding_days' => 1.5], 'minimum_holding_days'], [['minimum_holding_days' => 30], 'minimum_holding_days'],
    [['forfeiture_bp' => 10001], 'forfeiture_bp'], [['principal_penalty_bp' => -1], 'principal_penalty_bp'],
]);

test('upgrading populated savings retains legacy snapshots settlements notices and financial guards', function (): void {
    $original = DB::getDefaultConnection();
    config(['database.connections.savings_upgrade' => [...config('database.connections.sqlite'), 'database' => ':memory:']]);
    DB::setDefaultConnection('savings_upgrade');
    $migration = database_path('migrations/2026_11_07_212545_extend_savings_for_compounding_and_closure.php');
    $baseline = array_values(array_filter(glob(database_path('migrations/*.php')), fn (string $path): bool => basename($path) < basename($migration)));
    try {
        $this->artisan('migrate', ['--database' => 'savings_upgrade', '--path' => $baseline, '--realpath' => true, '--force' => true, '--no-interaction' => true])->assertSuccessful();
        $legacyVersion = SavingsProductVersion::factory()->create(['contract' => ['name' => 'Legacy savings', 'disclosure' => 'Legacy fixture',
            'minimum' => '1', 'maximum' => '1000000', 'withholding_bp' => 1000, 'terms' => [['days' => 30, 'annual_rate_bp' => 0]],
            'interest' => 'simple', 'day_count' => 'Actual/365', 'lock' => 'until_maturity', 'fee' => '0']]);
        $plan = SavingsPlan::factory()->create(['savings_product_version_id' => $legacyVersion->id,
            'contract' => $legacyVersion->contract + ['principal' => '10000', 'days' => 30, 'annual_rate_bp' => 0]]);
        postWalletTestCredit($plan->user, kobo: 10000);
        foreach (['principal', 'interest'] as $suffix) {
            (new LedgerAccount)->forceFill(['code' => 'customer:'.$plan->user_id.':plan:'.$plan->id.':'.$suffix,
                'type' => 'liability', 'user_id' => $plan->user_id])->save();
        }
        $funding = app(Ledger::class)->post('legacy-funding', [
            ['account_id' => LedgerAccount::where('code', 'customer:'.$plan->user_id.':wallet')->sole()->id, 'amount' => new Money(10000)],
            ['account_id' => app(SavingsPlans::class)->account($plan, 'principal')->id, 'amount' => new Money(-10000)],
        ], '2026-11-07', 'Legacy funding fixture', $plan->user_id);
        $start = app(Clock::class)->now()->setTimezone(config('app.timezone'))->startOfDay()->addDay();
        $plan->forceFill(['state' => 'active', 'funded_at' => app(Clock::class)->now(), 'accrual_starts_at' => $start,
            'matures_at' => $start->addDays(30), 'funding_journal_id' => $funding->id])->save();
        $contract = DB::table('savings_plans')->where('id', $plan->id)->value('contract');
        $version = DB::table('savings_product_versions')->where('id', $plan->savings_product_version_id)->value('contract');
        $journal = app(Ledger::class)->post('legacy-settlement', [
            ['account_id' => app(SavingsPlans::class)->account($plan, 'principal')->id, 'amount' => new Money(10000)],
            ['account_id' => LedgerAccount::where('code', 'customer:'.$plan->user_id.':wallet')->sole()->id, 'amount' => new Money(-10000)],
        ], '2026-12-08', 'Legacy maturity fixture', $plan->user_id);
        DB::table('plan_settlements')->insert(['savings_plan_id' => $plan->id, 'journal_id' => $journal->id, 'principal' => 10000,
            'gross_interest' => 0, 'net_interest' => 0, 'remaining_debt' => 0, 'withholding' => 0, 'debt_repayment' => 0,
            'wallet_credit' => 10000, 'rounding_micro_kobo' => '0', 'posted_at' => app(Clock::class)->now()]);
        $plan->forceFill(['state' => 'closed', 'closure_reason' => 'matured_withdrawal'])->save();
        (new SavingsNotice)->forceFill(['id' => (string) Str::uuid(), 'savings_plan_id' => $plan->id, 'user_id' => $plan->user_id,
            'recipient' => $plan->user->email, 'data' => ['type' => 'savings_maturity'], 'created_at' => app(Clock::class)->now(), 'next_attempt_at' => app(Clock::class)->now()])->save();
        $this->artisan('migrate', ['--database' => 'savings_upgrade', '--path' => [$migration], '--realpath' => true, '--force' => true, '--no-interaction' => true])->assertSuccessful();

        expect(DB::table('savings_plans')->where('id', $plan->id)->value('contract'))->toBe($contract);
        expect(DB::table('savings_product_versions')->where('id', $plan->savings_product_version_id)->value('contract'))->toBe($version);
        expect(DB::table('plan_settlements')->sole()->wallet_credit)->toBe(10000);
        expect(DB::table('plan_settlements')->sole()->capitalized_interest)->toBe(0);
        expect(SavingsNotice::sole()->data['type'])->toBe('savings_maturity');
        expect($plan->fresh()->rollover_election)->toBeNull();
        expect(fn () => DB::table('savings_plans')->where('id', $plan->id)->update(['contract' => '{}']))->toThrow(QueryException::class, 'immutable');
        expect(fn () => DB::table('plan_settlements')->where('savings_plan_id', $plan->id)->delete())->toThrow(QueryException::class, 'immutable');
        expect(app(Ledger::class)->projectionMismatches())->toBe([]);
    } finally {
        DB::disconnect('savings_upgrade');
        DB::setDefaultConnection($original);
    }
});
