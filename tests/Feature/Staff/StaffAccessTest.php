<?php

use App\Models\Staff;
use App\Models\User;
use Database\Seeders\StaffPermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\MultiFactor\Pages\SetUpRequiredMultiFactorAuthentication;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;

beforeEach(function () {
    $this->seed(StaffPermissionSeeder::class);
});

test('customers cannot enter either staff panel with a customer session', function (string $panel) {
    $customer = User::factory()->create();

    $this->actingAs($customer, 'web')->get('/'.$panel)
        ->assertRedirect(route('filament.'.$panel.'.auth.login'));
    $this->assertGuest('staff');
})->with(['admin', 'ops']);

test('customer credentials cannot authenticate through a staff login', function () {
    $customer = User::factory()->create();
    Filament::setCurrentPanel('admin');

    Livewire::test(Login::class)
        ->fillForm(['email' => $customer->email, 'password' => 'password'])
        ->call('authenticate')
        ->assertHasFormErrors(['email']);

    $this->assertGuest('staff');
});

test('staff credentials cannot authenticate through customer login', function () {
    $staff = Staff::factory()->create();
    $staff->assignRole('admin');

    $this->post(route('login.store'), ['email' => $staff->email, 'password' => 'password'])
        ->assertSessionHasErrors('email');

    $this->assertGuest('web');
});

test('staff sessions do not grant access to customer routes', function (string $path) {
    $staff = Staff::factory()->withTwoFactor()->create();
    $staff->assignRole('admin');
    Auth::guard('staff')->login($staff);

    $this->get($path)->assertRedirect(route('login'));
    $this->assertGuest('web');
})->with(['/dashboard', '/settings/profile', '/settings/security']);

test('panel access follows the assigned staff role', function (string $role, string $panel, bool $allowed) {
    $staff = Staff::factory()->withTwoFactor()->create();
    $staff->assignRole($role);
    Auth::guard('staff')->login($staff);

    $response = $this->get('/'.$panel);

    if ($allowed) {
        $response->assertOk();
    } else {
        $response->assertForbidden();
    }
})->with([
    ['manager', 'ops', true], ['manager', 'admin', false],
    ['admin', 'ops', true], ['admin', 'admin', true],
]);

test('staff must set up two factor authentication before entering a panel', function (string $panel) {
    $staff = Staff::factory()->create();
    $staff->assignRole('admin');
    Auth::guard('staff')->login($staff);

    $this->get('/'.$panel)->assertRedirect(route('filament.'.$panel.'.auth.multi-factor-authentication.set-up-required'));
})->with(['admin', 'ops']);

test('staff login challenges a configured second factor before authenticating', function (string $panel) {
    $staff = Staff::factory()->withTwoFactor()->create();
    $staff->assignRole('admin');
    Filament::setCurrentPanel($panel);

    $login = Livewire::test(Login::class)
        ->fillForm(['email' => $staff->email, 'password' => 'password'])
        ->call('authenticate')
        ->assertHasNoFormErrors();

    expect($login->get('userUndertakingMultiFactorAuthentication'))->not->toBeNull();
    $this->assertGuest('staff');
})->with(['admin', 'ops']);

test('inactive staff cannot sign in even with valid credentials', function () {
    $staff = Staff::factory()->create(['is_active' => false]);
    $staff->assignRole('admin');
    Filament::setCurrentPanel('admin');

    Livewire::test(Login::class)
        ->fillForm(['email' => $staff->email, 'password' => 'password'])
        ->call('authenticate')
        ->assertHasFormErrors(['email']);

    $this->assertGuest('staff');
});

test('deactivated staff lose existing panel access', function () {
    $staff = Staff::factory()->withTwoFactor()->create();
    $staff->assignRole('admin');
    Auth::guard('staff')->login($staff);
    $staff->update(['is_active' => false]);

    $this->get('/admin')->assertForbidden();
});

test('staff and customer password tokens are independent even for the same email', function () {
    $customer = User::factory()->create();
    $staff = Staff::factory()->create(['email' => $customer->email]);
    $customerToken = Password::broker('users')->createToken($customer);
    $staffToken = Password::broker('staff')->createToken($staff);

    expect(Password::broker('users')->tokenExists($customer, $customerToken))->toBeTrue()
        ->and(Password::broker('staff')->tokenExists($staff, $staffToken))->toBeTrue()
        ->and(Password::broker('users')->tokenExists($customer, $staffToken))->toBeFalse()
        ->and(Password::broker('staff')->tokenExists($staff, $customerToken))->toBeFalse();
});

test('staff registration is unavailable', function (string $panel) {
    $this->get('/'.$panel.'/register')->assertNotFound();
})->with(['admin', 'ops']);

test('staff can complete the configured TOTP challenge and a used code cannot be replayed', function () {
    $staff = Staff::factory()->withTwoFactor()->create();
    $staff->assignRole('admin');
    Filament::setCurrentPanel('admin');
    $code = app(Google2FA::class)->getCurrentOtp($staff->getAppAuthenticationSecret());

    $login = Livewire::test(Login::class)
        ->fillForm(['email' => $staff->email, 'password' => 'password'])
        ->call('authenticate');
    $login->set('data.multiFactor.app.code', $code)
        ->call('authenticate')->assertHasNoFormErrors();
    $this->assertAuthenticatedAs($staff, 'staff');
    $this->assertGuest('web');

    Auth::guard('staff')->logout();
    Livewire::test(Login::class)
        ->fillForm(['email' => $staff->email, 'password' => 'password'])
        ->call('authenticate')
        ->set('data.multiFactor.app.code', $code)
        ->call('authenticate')
        ->assertHasFormErrors(['multiFactor.app.code']);
    $this->assertGuest('staff');
});

test('staff recovery codes authenticate once and remain encrypted and hidden', function () {
    $staff = Staff::factory()->withTwoFactor()->create();
    $staff->assignRole('admin');
    Filament::setCurrentPanel('admin');
    $provider = AppAuthentication::make()->recoverable();
    $provider->saveRecoveryCodes($staff, ['single-use-recovery']);

    $login = Livewire::test(Login::class)
        ->fillForm(['email' => $staff->email, 'password' => 'password'])
        ->call('authenticate');
    $login->set('data.multiFactor.app.useRecoveryCode', true)
        ->set('data.multiFactor.app.recoveryCode', 'single-use-recovery')
        ->call('authenticate')->assertHasNoFormErrors();

    $this->assertAuthenticatedAs($staff, 'staff');
    expect($staff->fresh()->getAppAuthenticationRecoveryCodes())->toBe([])
        ->and($staff->getRawOriginal('app_authentication_secret'))->not->toBe($staff->getAppAuthenticationSecret())
        ->and($staff->toArray())->not->toHaveKeys(['password', 'app_authentication_secret', 'app_authentication_recovery_codes']);
    Auth::guard('staff')->logout();

    Livewire::test(Login::class)
        ->fillForm(['email' => $staff->email, 'password' => 'password'])
        ->call('authenticate')
        ->set('data.multiFactor.app.useRecoveryCode', true)
        ->set('data.multiFactor.app.recoveryCode', 'single-use-recovery')
        ->call('authenticate')->assertHasFormErrors(['multiFactor.app.recoveryCode']);
    $this->assertGuest('staff');
});

test('staff can enroll an authenticator before continuing to the panel', function () {
    $staff = Staff::factory()->create();
    $staff->assignRole('admin');
    Filament::setCurrentPanel('admin');
    $this->actingAs($staff, 'staff');

    $setup = Livewire::test(SetUpRequiredMultiFactorAuthentication::class)
        ->mountAction(TestAction::make('setUpAppAuthentication')->schemaComponent(true, 'content'));
    $arguments = decrypt($setup->instance()->getMountedAction()->getArguments()['encrypted']);
    $code = app(Google2FA::class)->getCurrentOtp($arguments['secret']);

    $setup->fillForm(['password' => 'password', 'code' => $code])
        ->goToNextWizardStep()
        ->callMountedAction()
        ->assertHasNoFormErrors();

    expect($staff->fresh()->getAppAuthenticationSecret())->toBe($arguments['secret'])
        ->and($staff->fresh()->getAppAuthenticationRecoveryCodes())->toHaveCount(8);
    $this->get('/admin')->assertOk();
});
