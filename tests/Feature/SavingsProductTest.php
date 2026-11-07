<?php

use App\Contracts\Clock;
use App\Filament\Resources\SavingsProducts\Pages\ManageSavingsProducts;
use App\Models\SavingsPlan;
use App\Models\SavingsProduct;
use App\Models\SavingsProductVersion;
use App\Models\Staff;
use App\Models\User;
use App\Support\Approvals;
use App\Support\FrozenClock;
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
