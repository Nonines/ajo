<?php

use App\Models\Deposit;
use App\Models\LedgerAccount;
use App\Models\PaymentException;
use App\Models\User;
use App\Support\Deposits;
use App\Support\Ledger;
use App\Support\Money;
use App\Support\Wallets;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

test('checkout initializes once and rejects changed idempotent input without journals', function () {
    $user = User::factory()->create();
    configurePaymentTest();
    $key = (string) Str::uuid();

    $deposit = app(Deposits::class)->initialize($user, new Money(500000), $key);
    $replay = app(Deposits::class)->initialize($user, new Money(500000), $key);

    expect($replay->id)->toBe($deposit->id);
    expect((string) $deposit->state)->toBe('pending_confirmation');
    expect($deposit->access_code)->toBe('test-access');
    Http::assertSentCount(1);
    $this->assertDatabaseCount('deposits', 1);
    $this->assertDatabaseCount('journals', 0);
    expect(fn () => app(Deposits::class)->initialize($user, new Money(500001), $key))->toThrow(DomainException::class);
});

test('verified gross receipt recognizes net value and fee provenance exactly once', function () {
    $user = User::factory()->create();
    $deposit = initializePaymentTest($user);
    fakeVerifiedPayment($deposit);

    app(Deposits::class)->verify($deposit);
    app(Deposits::class)->verify($deposit);

    expect((string) $deposit->refresh()->state)->toBe('confirmed');
    expect($deposit->wallet_credit)->toBe(500000);
    expect($deposit->gross_amount)->toBe(510000);
    expect($deposit->fee_amount)->toBe(10000);
    expect(app(Wallets::class)->provision($user)->balance->kobo)->toBe(-500000);
    expect(app(Ledger::class)->balance(LedgerAccount::where('code', 'house:cash_at_gateway')->sole())->kobo)->toBe(500000);
    $this->assertDatabaseCount('journals', 2);
    $this->assertDatabaseCount('payment_notices', 1);
    expect(app(Ledger::class)->projectionMismatches())->toBe([]);
});

test('deposit journals use the application date of the provider payment', function (string $timezone, string $expectedDate) {
    config(['app.timezone' => $timezone]);
    $deposit = initializePaymentTest(User::factory()->create());
    fakeVerifiedPayment($deposit, ['paid_at' => '2026-11-07T23:30:00Z']);

    app(Deposits::class)->verify($deposit);

    $deposit->refresh();
    $this->assertDatabaseHas('journals', ['id' => $deposit->recognition_journal_id, 'effective_date' => $expectedDate]);
    $this->assertDatabaseHas('journals', ['id' => $deposit->fee_journal_id, 'effective_date' => $expectedDate]);
})->with([
    'default UTC' => ['UTC', '2026-11-07'],
    'configured timezone' => ['Asia/Kolkata', '2026-11-08'],
]);

test('provider disagreement cannot recognize customer value', function (array $changes) {
    $deposit = initializePaymentTest(User::factory()->create());
    fakeVerifiedPayment($deposit, $changes);

    app(Deposits::class)->verify($deposit);

    expect($deposit->fresh()->recognition_journal_id)->toBeNull();
    $this->assertDatabaseCount('journals', 0);
    expect(PaymentException::count())->toBe(1);
})->with([
    'wrong currency' => [['currency' => 'USD']], 'wrong environment' => [['domain' => 'live']],
    'wrong customer' => [['customer' => ['email' => 'other@example.test']]], 'wrong reference' => [['reference' => 'other']],
    'wrong amount' => [['amount' => 510001]], 'wrong requested value' => [['requested_amount' => 500001]],
    'negative fee' => [['fees' => -10000]], 'fractional amount' => [['amount' => 510000.5]],
    'missing fee' => [['fees' => null]],
]);

test('failed payment can later confirm through verification with no success webhook', function () {
    $deposit = initializePaymentTest(User::factory()->create());
    fakeVerifiedPayment($deposit, ['status' => 'failed']);
    app(Deposits::class)->verify($deposit);
    expect((string) $deposit->refresh()->state)->toBe('failed');
    fakeVerifiedPayment($deposit);

    app(Deposits::class)->verify($deposit);

    expect((string) $deposit->refresh()->state)->toBe('confirmed');
    $this->assertDatabaseCount('journals', 2);
});

test('old failed observations cannot downgrade confirmed value', function () {
    $deposit = initializePaymentTest(User::factory()->create());
    fakeVerifiedPayment($deposit);
    app(Deposits::class)->verify($deposit);
    fakeVerifiedPayment($deposit, ['status' => 'failed']);

    app(Deposits::class)->verify($deposit);

    expect((string) $deposit->refresh()->state)->toBe('confirmed');
    $this->assertDatabaseCount('journals', 2);
    expect(PaymentException::where('code', 'conflicting_status')->exists())->toBeTrue();
});

test('a reversed status without refund amounts cannot terminally reverse an unrecognized deposit', function () {
    $deposit = initializePaymentTest(User::factory()->create());
    fakeVerifiedPayment($deposit, ['status' => 'reversed']);

    app(Deposits::class)->verify($deposit);

    expect((string) $deposit->fresh()->state)->toBe('pending_confirmation');
    expect($deposit->fresh()->recognized_at)->toBeNull();
    expect(PaymentException::where('code', 'reversal_evidence_required')->whereNull('resolved_at')->exists())->toBeTrue();
    $this->assertDatabaseCount('journals', 0);
    $this->assertDatabaseCount('payment_reversals', 0);
});

test('pending checkout retains a closed customer and later recognition uses the original email', function () {
    $user = User::factory()->create();
    $deposit = initializePaymentTest($user);
    app(Wallets::class)->close($user);
    $user->refresh()->forceFill(['email' => 'new-unverified@example.test', 'email_verified_at' => null])->save();
    fakeVerifiedPayment($deposit);

    app(Deposits::class)->verify($deposit);

    expect($user->fresh()->is_active)->toBeFalse();
    expect($deposit->fresh()->wallet_credit)->toBe(500000);
    expect(app(Wallets::class)->provision($user)->balance->kobo)->toBe(-500000);
});

test('deactivated and unverified customers cannot initialize checkout', function (string $condition) {
    $user = User::factory()->create();
    configurePaymentTest();
    $user->forceFill($condition === 'inactive' ? ['is_active' => false] : ['email_verified_at' => null])->save();

    expect(fn () => app(Deposits::class)->initialize($user, new Money(500000), (string) Str::uuid()))->toThrow(DomainException::class);

    Http::assertNothingSent();
    $this->assertDatabaseCount('deposits', 0);
})->with(['inactive', 'unverified']);

test('invalid customer net amounts return validation errors without provider requests', function (mixed $amount) {
    $user = User::factory()->create();
    configurePaymentTest();

    $this->actingAs($user)->post(route('deposits.store'), ['amount' => $amount, 'request_key' => (string) Str::uuid()])->assertInvalid('amount');

    Http::assertNothingSent();
    $this->assertDatabaseCount('deposits', 0);
})->with(['999.99', '100000000.01', '1e6', '1000.001', '-1000', 1000.0, [['1000']]]);

test('customer checkout ignores ownership fields and exposes only its own status', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    configurePaymentTest();

    $this->actingAs($owner)->post(route('deposits.store'), ['amount' => '1000.00', 'request_key' => (string) Str::uuid(), 'user_id' => $other->id])->assertRedirect();
    $deposit = Deposit::sole();

    expect($deposit->user_id)->toBe($owner->id);
    $this->actingAs($other)->get(route('deposits.show', $deposit->reference))->assertNotFound();
    $this->post(route('deposits.confirm', $deposit->reference))->assertNotFound();
    $this->actingAs($owner)->get(route('deposits.show', $deposit->reference))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Deposits/Show')->where('deposit.requestedNet', '100000')->missing('deposit.payer_email'));
});

test('guests and unverified sessions cannot access deposit endpoints', function () {
    $this->get(route('deposits.index'))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->unverified()->create())->get(route('deposits.index'))->assertRedirect(route('verification.notice'));
});

test('initialization timeout retains the reference and can recover a later verified payment', function () {
    config(['payments.secret_key' => 'sk_test_fixture']);
    Queue::fake();
    Http::preventStrayRequests();
    Http::fake(['api.paystack.co/transaction/initialize' => Http::failedConnection()]);
    $user = User::factory()->create();

    $deposit = app(Deposits::class)->initialize($user, new Money(500000), (string) Str::uuid());
    app(Wallets::class)->close($user);
    fakeVerifiedPayment($deposit);
    app(Deposits::class)->verify($deposit);

    expect($user->fresh()->is_active)->toBeFalse();
    expect($deposit->refresh()->wallet_credit)->toBe(500000);
    expect((string) $deposit->state)->toBe('confirmed');
});
