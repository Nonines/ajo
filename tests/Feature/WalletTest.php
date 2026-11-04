<?php

use App\Actions\Fortify\CreateNewUser;
use App\Models\Journal;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Support\Ledger;
use App\Support\Money;
use App\Support\Wallets;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

test('registration provisions exactly one empty wallet without opening journals', function () {
    $this->post(route('register.store'), ['name' => 'Customer', 'email' => 'wallet@example.test', 'password' => 'Password123!', 'password_confirmation' => 'Password123!'])->assertSessionHasNoErrors();
    $user = User::where('email', 'wallet@example.test')->sole();
    $wallet = app(Wallets::class)->provision($user);
    expect(app(Wallets::class)->provision($user)->id)->toBe($wallet->id);
    expect($wallet->balance->kobo)->toBe(0);
    $this->assertDatabaseCount('journals', 0);
});

test('older customers are provisioned idempotently with no invented opening balance', function () {
    User::factory()->count(2)->create();
    $this->artisan('wallets:provision')->assertSuccessful();
    $this->artisan('wallets:provision')->assertSuccessful();
    expect(LedgerAccount::whereNotNull('user_id')->count())->toBe(4);
    $this->assertDatabaseCount('journals', 0);
});

test('wallet exposes only the authenticated customers balance and paginated history', function () {
    $user = User::factory()->create();
    postWalletTestCredit($user, 'mine', 9007199254740993);
    postWalletTestCredit(User::factory()->create(), 'other');

    $this->actingAs($user, 'web')->get(route('wallet.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Wallet')->where('balance', '9007199254740993')->has('history.data', 1)
        ->where('history.data.0.amount', '9007199254740993')->where('history.data.0.description', 'Test credit'));
});

test('wallet requires customer authentication and verified active identity', function () {
    $this->get(route('wallet.index'))->assertRedirect(route('login'));
    $user = User::factory()->unverified()->create();
    app(Wallets::class)->provision($user);
    $this->actingAs($user, 'web')->get(route('wallet.index'))->assertRedirect(route('verification.notice'));
    $user->forceFill(['email_verified_at' => now(), 'is_active' => false])->save();
    $this->actingAs($user, 'web')->get(route('wallet.index'))->assertForbidden();
});

test('unused wallet structures do not prevent permanent account deletion', function () {
    $user = User::factory()->create();
    app(Wallets::class)->provision($user);

    $this->actingAs($user)->delete(route('profile.destroy'), ['password' => 'password'])->assertRedirect(route('home'));

    expect($user->fresh())->toBeNull();
    expect(LedgerAccount::where('user_id', $user->id)->exists())->toBeFalse();
});

test('zero balance customers with financial history are deactivated and references remain', function () {
    $user = User::factory()->create();
    $journal = postWalletTestCredit($user);
    app(Ledger::class)->reverse($journal, 'reverse', '2026-11-02', 'Correction', $user->id);

    $this->actingAs($user)->delete(route('profile.destroy'), ['password' => 'password'])->assertRedirect(route('home'));

    expect($user->fresh()->is_active)->toBeFalse();
    $this->assertGuest('web');
    $this->assertDatabaseCount('journals', 2);
    expect(app(Wallets::class)->provision($user)->balance->kobo)->toBe(0);
});

test('deactivated customers cannot spend through services even with a previously authenticated session', function () {
    $user = User::factory()->create();
    postWalletTestCredit($user);
    $wallet = app(Wallets::class)->provision($user);
    $this->actingAs($user, 'web');
    DB::table('users')->where('id', $user->id)->update(['is_active' => false]);
    $entries = [['account_id' => $wallet->id, 'amount' => new Money(100)], ['account_id' => LedgerAccount::where('code', 'house:cash_at_gateway')->sole()->id, 'amount' => new Money(-100)]];

    expect(fn () => app(Ledger::class)->post('inactive-spend', $entries, '2026-11-02', 'Spend', $user->id))->toThrow(DomainException::class);

    expect(Journal::count())->toBe(1);
    expect($wallet->fresh()->balance->kobo)->toBe(-10000);
});

test('registration rolls back the customer when wallet provisioning fails', function () {
    DB::unprepared("CREATE TRIGGER reject_wallet BEFORE INSERT ON ledger_accounts BEGIN SELECT RAISE(ABORT, 'Injected wallet failure'); END");
    $input = ['name' => 'Customer', 'email' => 'wallet@example.test', 'password' => 'Password123!', 'password_confirmation' => 'Password123!'];

    expect(fn () => app(CreateNewUser::class)->create($input))->toThrow(QueryException::class);

    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('journals', 0);
    expect(LedgerAccount::whereNotNull('user_id')->count())->toBe(0);
});

test('unverified customer sessions cannot spend through the posting service', function () {
    $user = User::factory()->unverified()->create();
    postWalletTestCredit($user);
    $wallet = app(Wallets::class)->provision($user);
    $entries = [['account_id' => $wallet->id, 'amount' => new Money(100)], ['account_id' => LedgerAccount::where('code', 'house:cash_at_gateway')->sole()->id, 'amount' => new Money(-100)]];

    expect(fn () => app(Ledger::class)->post('unverified', $entries, '2026-11-02', 'Spend', $user->id))->toThrow(DomainException::class);

    expect($wallet->fresh()->balance->kobo)->toBe(-10000);
    $this->assertDatabaseCount('journals', 1);
});

test('wallet history formats the minimum signed entry as an exact positive decimal string', function () {
    $user = User::factory()->create();
    $wallet = app(Wallets::class)->provision($user);
    $cash = LedgerAccount::where('code', 'house:cash_at_gateway')->sole();
    app(Ledger::class)->post('wallet-minimum-line', [
        ['account_id' => $wallet->id, 'amount' => new Money(PHP_INT_MIN)],
        ['account_id' => $wallet->id, 'amount' => new Money(PHP_INT_MAX)],
        ['account_id' => $cash->id, 'amount' => new Money(1)],
    ], '2026-11-01', 'Exact line amounts', $user->id);

    $this->actingAs($user, 'web')->get(route('wallet.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Wallet')->where('balance', '1')->has('history.data', 2)
        ->where('history.data.0.amount', '-9223372036854775807')
        ->where('history.data.1.amount', '9223372036854775808'));
});
