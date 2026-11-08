<?php

use App\Jobs\DeliverPaymentNotice;
use App\Jobs\ProcessPaymentWebhook;
use App\Jobs\ScanPayments;
use App\Jobs\VerifyDeposit;
use App\Models\Deposit;
use App\Models\Journal;
use App\Models\LedgerAccount;
use App\Models\SavedDestination;
use App\Models\SavingsPlan;
use App\Models\Staff;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Support\Deposits;
use App\Support\Ledger;
use App\Support\Money;
use App\Support\SavingsPlans;
use App\Support\Wallets;
use App\Support\Withdrawals;
use Database\Seeders\StaffPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
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

function configurePaymentTest(): void
{
    config(['payments.secret_key' => 'sk_test_fixture', 'inertia.ssr.enabled' => false]);
    Queue::fake([
        ProcessPaymentWebhook::class, VerifyDeposit::class,
        DeliverPaymentNotice::class, ScanPayments::class,
    ]);
    Http::preventStrayRequests();
    Http::fake([
        'api.paystack.co/transaction/initialize' => fn ($request) => Http::response([
            'status' => true, 'data' => ['reference' => $request['reference'], 'access_code' => 'test-access'],
        ]),
    ]);
}

function initializePaymentTest(User $user, int $kobo = 500000): Deposit
{
    configurePaymentTest();

    return app(Deposits::class)->initialize($user, new Money($kobo), (string) Str::uuid());
}

/** @param array<string, mixed> $changes */
function fakeVerifiedPayment(Deposit $deposit, array $changes = []): void
{
    config(['test_payments.'.$deposit->reference => array_replace([
        'id' => $deposit->id + 1000, 'reference' => $deposit->reference, 'customer' => ['email' => $deposit->payer_email],
        'domain' => 'test', 'currency' => 'NGN', 'status' => 'success', 'amount' => $deposit->requested_net + 10000,
        'fees' => 10000, 'requested_amount' => $deposit->requested_net, 'paid_at' => '2026-11-04T10:00:00Z',
    ], $changes)]);
    Http::fake([
        'api.paystack.co/transaction/verify/'.$deposit->reference => fn () => Http::response(['status' => true, 'data' => config('test_payments.'.$deposit->reference)]),
    ]);
}

/** @param array<string, mixed> $changes */
function fakeVerifiedRefund(Deposit $deposit, string $id, int $amount, array $changes = []): void
{
    Http::fake([
        'api.paystack.co/refund/'.$id => Http::response(['status' => true, 'data' => array_replace([
            'id' => $id, 'transaction' => $deposit->provider_id, 'domain' => 'test', 'currency' => 'NGN', 'status' => 'processed',
            'amount' => $amount, 'deducted_amount' => $amount, 'fully_deducted' => true,
        ], $changes)]),
    ]);
}

/** @param array<string, mixed> $changes */
function fundedAccrualPlan(int $principal = 1000000, int $rate = 1000, array $changes = []): SavingsPlan
{
    $plan = SavingsPlan::factory()->create(['contract' => array_replace(['name' => 'Accrual test', 'disclosure' => 'Fixture',
        'minimum' => '1', 'maximum' => (string) PHP_INT_MAX, 'withholding_bp' => 1000, 'terms' => [['days' => 30, 'annual_rate_bp' => $rate]],
        'interest' => 'simple', 'day_count' => 'Actual/365', 'lock' => 'until_maturity', 'fee' => '0',
        'principal' => (string) $principal, 'days' => 30, 'annual_rate_bp' => $rate], $changes)]);
    postWalletTestCredit($plan->user, key: 'savings-credit:'.$plan->id, kobo: $principal);
    app(SavingsPlans::class)->accept($plan->user, $plan);

    return app(SavingsPlans::class)->fund($plan->user, $plan);
}

function payoutStaff(string $role = 'manager'): Staff
{
    app(StaffPermissionSeeder::class)->run();
    $staff = Staff::factory()->withTwoFactor()->create();
    $staff->assignRole($role);

    return $staff;
}

function requestedPayout(int $principal = 100000, int $remaining = 0): WithdrawalRequest
{
    $user = User::factory()->create();
    $destination = SavedDestination::factory()->for($user)->create();
    $fee = app(Withdrawals::class)->quote(new Money($principal))->kobo;
    postWalletTestCredit($user, 'fund:'.$user->id, $principal + $fee + $remaining);

    return app(Withdrawals::class)->request($user, $destination, new Money($principal), (string) Str::uuid());
}

function fakePayoutOutcome(WithdrawalRequest $withdrawal, string $status = 'success', ?int $fee = 1000): void
{
    Http::preventStrayRequests();
    $data = ['id' => $withdrawal->id + 10000, 'reference' => $withdrawal->reference, 'domain' => 'test',
        'currency' => 'NGN', 'status' => $status, 'amount' => $withdrawal->principal,
        'recipient' => ['recipient_code' => $withdrawal->destination['recipient_code']]];
    if ($fee !== null) {
        $data['fee_charged'] = $fee;
    }
    config(['payout_fixture.'.$withdrawal->id => $data]);
    Http::fake([
        'api.paystack.co/transfer' => function ($request) use ($withdrawal) {
            expect(app(Withdrawals::class)->providerApproval($request->data()))->toBeTrue();

            return Http::response(['status' => true, 'data' => ['reference' => $withdrawal->reference]]);
        },
        'api.paystack.co/transfer/verify/'.$withdrawal->reference => fn () => Http::response(['status' => true, 'data' => config('payout_fixture.'.$withdrawal->id)]),
        'api.paystack.co/transfer/'.($withdrawal->id + 10000) => fn () => Http::response(['status' => true, 'data' => config('payout_fixture.'.$withdrawal->id)]),
    ]);
}
