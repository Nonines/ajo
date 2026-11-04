<?php

use App\Models\Journal;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Support\Ledger;
use App\Support\Money;
use App\Support\Wallets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

pest()->beforeEach(function (): void {
    config(['ledger.lock_store' => 'array']);
})->in('Feature');

function postWalletTestCredit(User $user, string $key = 'credit', int $kobo = 10000): Journal
{
    $wallet = app(Wallets::class)->provision($user);

    return app(Ledger::class)->post($key, [
        ['account_id' => $wallet->id, 'amount' => new Money(-$kobo)],
        ['account_id' => LedgerAccount::where('code', 'house:cash_at_gateway')->sole()->id, 'amount' => new Money($kobo)],
    ], '2026-11-01', 'Test credit', $user->id);
}
