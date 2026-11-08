<?php

use App\Filament\Resources\Staff\Pages\ManageStaff;
use App\Models\Staff;
use App\Models\User;
use Database\Seeders\StaffPermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset;
use Filament\Auth\Pages\PasswordReset\ResetPassword;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(StaffPermissionSeeder::class);
    Filament::setCurrentPanel('admin');
});

test('initial admin command creates one administrator without disclosing the password', function () {
    $this->artisan('staff:create-initial-admin', ['--name' => 'Initial Admin', '--email' => 'admin@example.com'])
        ->expectsQuestion('Password', 'correct-horse-42')
        ->expectsQuestion('Confirm password', 'correct-horse-42')
        ->assertSuccessful();

    $admin = Staff::query()->sole();
    expect($admin->hasRole('admin'))->toBeTrue()
        ->and(Hash::check('correct-horse-42', $admin->password))->toBeTrue()
        ->and($admin->getAppAuthenticationSecret())->toBeNull();
    $this->assertDatabaseCount('users', 0);

    $this->artisan('staff:create-initial-admin', ['--name' => 'Another', '--email' => 'other@example.com'])
        ->assertFailed();
    $this->assertDatabaseCount('staff', 1);
});

test('initial admin command rejects invalid input without creating staff', function () {
    $this->artisan('staff:create-initial-admin', ['--name' => 'Admin', '--email' => 'not-an-email'])
        ->expectsQuestion('Password', 'correct-horse-42')
        ->expectsQuestion('Confirm password', 'does-not-match')
        ->assertFailed();

    $this->assertDatabaseCount('staff', 0);
});

test('an admin can create and deactivate staff through the staff resource', function () {
    $admin = Staff::factory()->withTwoFactor()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin, 'staff');
    $managerRole = Role::findByName('manager', 'staff');

    Livewire::test(ManageStaff::class)
        ->callAction('create', data: [
            'name' => 'Operations Manager', 'email' => 'manager@example.com',
            'password' => 'manager-password-42', 'roles' => [$managerRole->id],
            'permissions' => [], 'is_active' => true,
        ])
        ->assertHasNoFormErrors();

    $manager = Staff::query()->where('email', 'manager@example.com')->sole();
    expect($manager->hasRole('manager'))->toBeTrue()
        ->and(Hash::check('manager-password-42', $manager->password))->toBeTrue();

    Livewire::test(ManageStaff::class)
        ->callAction(TestAction::make('edit')->table($manager), data: [
            'name' => $manager->name, 'email' => $manager->email,
            'password' => '', 'roles' => [$managerRole->id],
            'permissions' => [], 'is_active' => false,
        ])
        ->assertHasNoFormErrors();

    expect($manager->fresh()->is_active)->toBeFalse()
        ->and(Hash::check('manager-password-42', $manager->fresh()->password))->toBeTrue();
    $this->assertDatabaseHas('staff', ['id' => $manager->id]);
});

test('staff management checks action permissions independently of panel access', function () {
    $admin = Staff::factory()->withTwoFactor()->create();
    $admin->assignRole('admin');
    Role::findByName('admin', 'staff')->revokePermissionTo('staff.manage');
    Auth::guard('staff')->login($admin);

    $this->get('/admin')->assertOk();
    $this->get('/admin/staff')->assertForbidden();
    expect(Gate::forUser($admin)->allows('create', Staff::class))->toBeFalse();
});

test('manager and admin permission assignments follow the accepted access matrix', function () {
    $manager = Staff::factory()->create();
    $manager->assignRole('manager');
    $admin = Staff::factory()->create();
    $admin->assignRole('admin');

    foreach (['customers.view', 'exceptions.manage', 'withdrawals.review', 'withdrawals.make',
        'withdrawals.check', 'adjustments.make', 'adjustments.check', 'waivers.make',
        'waivers.check', 'plans.hold', 'plans.release', 'recovery.release'] as $permission) {
        expect($manager->can($permission))->toBeTrue();
        expect($admin->can($permission))->toBeTrue();
    }
    foreach (['products.manage', 'rates.make', 'rates.check', 'staff.manage', 'simulator.use',
        'audit.view', 'plans.close', 'recovery.write-off', 'horizon.view'] as $permission) {
        expect($manager->can($permission))->toBeFalse();
        expect($admin->can($permission))->toBeTrue();
    }
    expect(Gate::forUser($manager)->allows('create', Staff::class))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('delete', $manager))->toBeFalse();
});

test('staff password recovery changes only the staff password', function () {
    $customer = User::factory()->create();
    $staff = Staff::factory()->withTwoFactor()->create(['email' => $customer->email]);
    $staff->assignRole('admin');
    Notification::fake();

    Livewire::test(RequestPasswordReset::class)
        ->fillForm(['email' => $staff->email])
        ->call('request')->assertHasNoFormErrors();

    Notification::assertSentTo($staff, ResetPasswordNotification::class,
        function (ResetPasswordNotification $notification) use ($staff): bool {
            Livewire::test(ResetPassword::class, ['email' => $staff->email, 'token' => $notification->token])
                ->fillForm(['password' => 'new-password-42', 'passwordConfirmation' => 'new-password-42'])
                ->call('resetPassword')->assertHasNoFormErrors();

            return true;
        });

    expect(Hash::check('new-password-42', $staff->fresh()->password))->toBeTrue()
        ->and(Hash::check('password', $customer->fresh()->password))->toBeTrue()
        ->and($staff->fresh()->getAppAuthenticationSecret())->not->toBeNull();
    $this->assertGuest('staff');
});

test('Horizon denies customers, managers, inactive admins and admins without MFA', function (string $identity) {
    if ($identity === 'customer') {
        Auth::guard('web')->login(User::factory()->create());
    } elseif ($identity !== 'guest') {
        $staff = Staff::factory()->create(['is_active' => $identity !== 'inactive']);
        $staff->assignRole($identity === 'manager' ? 'manager' : 'admin');
        Auth::guard('staff')->login($staff);
    }

    $response = $this->get('/horizon');

    if (in_array($identity, ['guest', 'customer'], true)) {
        $response->assertRedirect(route('filament.admin.auth.login'));
    } else {
        $response->assertForbidden();
    }
})->with(['guest', 'customer', 'manager', 'inactive', 'admin-without-mfa']);

test('an active admin with MFA can access Horizon', function () {
    $staff = Staff::factory()->withTwoFactor()->create();
    $staff->assignRole('admin');
    Auth::guard('staff')->login($staff);

    $this->get('/horizon')->assertOk();
});

test('changing a staff password invalidates an existing Horizon session', function () {
    $staff = Staff::factory()->withTwoFactor()->create();
    $staff->assignRole('admin');
    Auth::guard('staff')->login($staff);
    $this->get('/admin')->assertOk();

    $staff->update(['password' => 'replacement-password-42']);

    $this->get('/horizon')->assertRedirect(route('filament.admin.auth.login'));
    $this->assertGuest('staff');
});
