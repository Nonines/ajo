<?php

use App\Models\Deposit;
use App\Models\Journal;
use App\Models\LedgerAccount;
use App\Models\PaymentException;
use App\Models\PaymentReversal;
use App\Models\User;
use App\Support\DepositRecovery;
use App\Support\Deposits;
use App\Support\GatewayFailure;
use App\Support\Ledger;
use App\Support\Money;
use App\Support\VerifiedPayment;
use App\Support\Wallets;
use Brick\Math\BigInteger;
use Illuminate\Support\Facades\Http;
use Random\Engine\Mt19937;
use Random\Randomizer;

function spendPaymentTestWallet(User $user, int $amount, string $key): void
{
    app(Ledger::class)->post($key, [
        ['account_id' => app(Wallets::class)->provision($user)->id, 'amount' => new Money($amount)],
        ['account_id' => LedgerAccount::where('code', 'house:cash_at_gateway')->sole()->id, 'amount' => new Money(-$amount)],
    ], '2026-11-04', 'Test spending', $user->id);
}

function paymentTestDebt(User $user): int
{
    return app(Ledger::class)->balance(LedgerAccount::where('code', 'customer:'.$user->id.':reversal_receivable')->sole())->kobo;
}

test('verified reversal uses available wallet funds then records debt without a negative wallet', function () {
    $user = User::factory()->create();
    $deposit = initializePaymentTest($user);
    fakeVerifiedPayment($deposit);
    app(Deposits::class)->verify($deposit);
    spendPaymentTestWallet($user, 400000, 'spend');
    fakeVerifiedRefund($deposit->refresh(), '2001', 500000);

    app(DepositRecovery::class)->refund('2001');
    app(DepositRecovery::class)->refund('2001');

    $effect = PaymentReversal::sole();
    expect($effect->principal)->toBe(500000);
    expect($effect->wallet_collected)->toBe(100000);
    expect($effect->receivable_created)->toBe(400000);
    expect(paymentTestDebt($user))->toBe(400000);
    expect(app(Wallets::class)->provision($user)->balance->kobo)->toBe(0);
    expect(app(Ledger::class)->projectionMismatches())->toBe([]);
});

test('refund consistency is checked against recognition committed during provider verification', function () {
    $user = User::factory()->create();
    $deposit = initializePaymentTest($user);
    $deposit->forceFill(['provider_id' => (string) ($deposit->id + 1000)])->save();
    fakeVerifiedRefund($deposit, '2801', 500000);
    $valid = ['id' => $deposit->provider_id, 'reference' => $deposit->reference, 'customer' => ['email' => $deposit->payer_email],
        'domain' => 'test', 'currency' => 'NGN', 'status' => 'success', 'amount' => 520000, 'fees' => 20000,
        'requested_amount' => 500000, 'paid_at' => '2026-11-04T10:00:00Z'];
    // The HTTP boundary allows another worker's recognition to commit before recovery locks.
    Http::fake(['api.paystack.co/transaction/verify/'.$deposit->reference => function () use ($deposit, $valid) {
        app(Deposits::class)->recognize($deposit, VerifiedPayment::from($valid));

        return Http::response(['status' => true, 'data' => array_replace($valid, ['amount' => 510000, 'fees' => 10000])]);
    }]);

    expect(fn () => app(DepositRecovery::class)->refund('2801'))->toThrow(DomainException::class, 'Refund transaction amounts conflict');

    $this->assertDatabaseCount('payment_reversals', 0);
    $this->assertDatabaseCount('journals', 2);
    expect($deposit->fresh()->fee_amount)->toBe(20000);
    expect(app(Wallets::class)->provision($user)->balance->kobo)->toBe(-500000);
    expect(paymentTestDebt($user))->toBe(0);
});

test('partial refund before recognition preserves unresolved evidence without a full reversal or guessed credit', function (string $status) {
    $user = User::factory()->create();
    $deposit = initializePaymentTest($user);
    $deposit->forceFill(['provider_id' => (string) ($deposit->id + 1000)])->save();
    fakeVerifiedPayment($deposit, ['status' => $status]);
    fakeVerifiedRefund($deposit, '2802', 100000);

    app(DepositRecovery::class)->refund('2802');
    app(Deposits::class)->verify($deposit);
    app(DepositRecovery::class)->refund('2802');

    expect((string) $deposit->fresh()->state)->toBe('pending_confirmation');
    expect($deposit->fresh()->recognized_at)->toBeNull();
    expect(PaymentReversal::sole()->journal_id)->toBeNull();
    expect(PaymentReversal::sole()->amount)->toBe(100000);
    expect(PaymentReversal::sole()->principal)->toBe(0);
    expect(PaymentReversal::sole()->evidence['id'])->toBe('2802');
    expect(PaymentException::where('code', 'unrecognized_refund')->where('provider_open', true)->whereNull('resolved_at')->exists())->toBeTrue();
    $this->assertDatabaseCount('journals', 0);
    expect(paymentTestDebt($user))->toBe(0);

    fakeVerifiedPayment($deposit, ['status' => 'reversed']);
    app(DepositRecovery::class)->refund('2802');
    app(Deposits::class)->verify($deposit);
    expect((string) $deposit->fresh()->state)->toBe('pending_confirmation');
    expect(PaymentException::where('code', 'unrecognized_refund')->whereNull('resolved_at')->exists())->toBeTrue();
    expect(PaymentReversal::count())->toBe(1);
    $this->assertDatabaseCount('journals', 0);
})->with(['success', 'reversed']);

test('unique cumulative refunds covering verified gross establish an unrecognized whole reversal without principal recovery', function () {
    $user = User::factory()->create();
    $deposit = initializePaymentTest($user);
    $deposit->forceFill(['provider_id' => (string) ($deposit->id + 1000)])->save();
    fakeVerifiedPayment($deposit, ['status' => 'reversed']);
    fakeVerifiedRefund($deposit, '2821', 100000);
    fakeVerifiedRefund($deposit, '2822', 410000);

    app(DepositRecovery::class)->refund('2821');
    app(DepositRecovery::class)->refund('2821');
    expect((string) $deposit->fresh()->state)->toBe('pending_confirmation');
    app(DepositRecovery::class)->refund('2822');
    app(DepositRecovery::class)->refund('2822');
    app(Deposits::class)->verify($deposit);

    expect((string) $deposit->fresh()->state)->toBe('reversed');
    expect(PaymentReversal::count())->toBe(2);
    expect(PaymentReversal::whereNotNull('journal_id')->count())->toBe(0);
    expect(PaymentException::whereIn('code', ['unrecognized_refund', 'reversal_evidence_required'])->whereNull('resolved_at')->exists())->toBeFalse();
    expect(PaymentException::where('code', 'unrecognized_reversal')->exists())->toBeTrue();
    expect(paymentTestDebt($user))->toBe(0);
    expect(app(Wallets::class)->provision($user)->balance->kobo)->toBe(0);
    $this->assertDatabaseCount('journals', 0);
});

test('a full refund with inconsistent original transaction evidence remains unresolved', function (array $changes) {
    $deposit = initializePaymentTest(User::factory()->create());
    $deposit->forceFill(['provider_id' => (string) ($deposit->id + 1000)])->save();
    fakeVerifiedPayment($deposit, array_replace(['status' => 'reversed'], $changes));
    fakeVerifiedRefund($deposit, '2823', 510000);

    app(DepositRecovery::class)->refund('2823');

    expect((string) $deposit->fresh()->state)->toBe('pending_confirmation');
    expect(PaymentReversal::sole()->evidence['id'])->toBe('2823');
    expect(PaymentException::where('code', 'unrecognized_refund')->where('provider_open', true)->whereNull('resolved_at')->exists())->toBeTrue();
    $this->assertDatabaseCount('journals', 0);
})->with([
    'fee no longer reconciles net' => [['fees' => 9999]],
    'provider requested value differs' => [['requested_amount' => 499999]],
    'provider still reports success' => [['status' => 'success']],
]);

test('old disputes resume historical refund pages after interruption', function () {
    $deposit = initializePaymentTest(User::factory()->create());
    fakeVerifiedPayment($deposit);
    app(Deposits::class)->verify($deposit);
    $deposit->refresh();
    $case = PaymentException::record('provider_dispute', 'Old open case', now()->toImmutable()->subYears(2), $deposit->id, resourceType: 'dispute', resourceId: '3801', providerOpen: true);
    config(['payments.batch_size' => 1, 'payments.pages_per_run' => 2]);
    fakeVerifiedRefund($deposit, '2803', 100000);
    fakeVerifiedRefund($deposit, '2804', 400000, ['dispute' => '3801']);
    $fail = true;
    Http::fake([
        'api.paystack.co/dispute/3801' => Http::response(['status' => true, 'data' => ['id' => '3801', 'domain' => 'test', 'transaction' => $deposit->provider_id,
            'status' => 'resolved', 'resolution' => 'merchant-accepted', 'refund_amount' => 400000]]),
        'api.paystack.co/refund?*' => function ($request) use (&$fail) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            expect($query)->not->toHaveKey('from');
            if ((int) $query['page'] === 2 && $fail) {
                return Http::response([], 503);
            }

            return Http::response(['status' => true, 'data' => match ((int) $query['page']) {
                1 => [['id' => '2803']], 2 => [['id' => '2804']], default => [],
            }]);
        },
    ]);

    config(['payments.pages_per_run' => 1]);
    app(DepositRecovery::class)->dispute('3801');
    expect($case->refresh()->next_refund_page)->toBe(2);
    expect($case->provider_open)->toBeTrue();
    config(['payments.pages_per_run' => 2]);
    expect(fn () => app(DepositRecovery::class)->dispute('3801'))->toThrow(GatewayFailure::class);
    expect($case->refresh()->next_refund_page)->toBe(2);
    $window = $case->refund_scan_to;
    expect($case->provider_open)->toBeTrue();
    $fail = false;
    app(DepositRecovery::class)->dispute('3801');

    expect(PaymentReversal::count())->toBe(2);
    expect($case->refresh()->provider_open)->toBeFalse();
    expect($case->refund_scan_to)->toBeNull();
    Http::assertSent(function ($request) use ($window): bool {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return ($query['page'] ?? null) === '2' && ($query['to'] ?? null) === $window->toIso8601String();
    });
    expect(app(Ledger::class)->projectionMismatches())->toBe([]);
});

test('interrupted dispute refund verification resumes within the same page without repeating completed items', function () {
    $deposit = initializePaymentTest(User::factory()->create());
    fakeVerifiedPayment($deposit);
    app(Deposits::class)->verify($deposit);
    fakeVerifiedRefund($deposit->refresh(), '2811', 100000);
    $fail = true;
    Http::fake([
        'api.paystack.co/dispute/3811' => Http::response(['status' => true, 'data' => ['id' => '3811', 'domain' => 'test', 'transaction' => $deposit->provider_id, 'status' => 'open']]),
        'api.paystack.co/refund?*' => Http::response(['status' => true, 'data' => [['id' => '2811'], ['id' => '2812']]]),
        'api.paystack.co/refund/2812' => function () use (&$fail, $deposit) {
            return $fail ? Http::response([], 503) : Http::response(['status' => true, 'data' => ['id' => '2812', 'transaction' => $deposit->provider_id,
                'domain' => 'test', 'currency' => 'NGN', 'status' => 'processed', 'amount' => 100000, 'deducted_amount' => 100000, 'fully_deducted' => true]]);
        },
    ]);

    expect(fn () => app(DepositRecovery::class)->dispute('3811'))->toThrow(GatewayFailure::class);
    $case = PaymentException::where('code', 'provider_dispute')->sole();
    expect($case->next_refund_page)->toBe(1);
    expect($case->refund_page_offset)->toBe(1);
    $fail = false;
    app(DepositRecovery::class)->dispute('3811');

    expect(PaymentReversal::count())->toBe(2);
    expect($case->refresh()->refund_page_offset)->toBe(0);
    expect(Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/refund/2811'))->count())->toBe(1);
    expect(app(Ledger::class)->projectionMismatches())->toBe([]);
});

test('a deposit entirely allocated to debt remains reversible and replay cannot restore debt twice', function () {
    $user = User::factory()->create();
    $first = initializePaymentTest($user, 900000);
    fakeVerifiedPayment($first, ['amount' => 900000, 'fees' => 0]);
    app(Deposits::class)->verify($first);
    spendPaymentTestWallet($user, 900000, 'spent-original');
    fakeVerifiedRefund($first->refresh(), '2101', 900000);
    app(DepositRecovery::class)->refund('2101');
    expect(paymentTestDebt($user))->toBe(900000);
    $second = initializePaymentTest($user);
    fakeVerifiedPayment($second, ['amount' => 500000, 'fees' => 0]);
    app(Deposits::class)->verify($second);
    expect(paymentTestDebt($user))->toBe(400000);
    expect($second->refresh()->debt_repayment)->toBe(500000);
    expect($second->wallet_credit)->toBe(0);
    fakeVerifiedRefund($second, '2102', 500000);

    app(DepositRecovery::class)->refund('2102');
    app(DepositRecovery::class)->refund('2102');

    expect(paymentTestDebt($user))->toBe(900000);
    expect(app(Wallets::class)->provision($user)->balance->kobo)->toBe(0);
    expect(PaymentReversal::where('deposit_id', $second->id)->sum('principal'))->toBe(500000);
    expect(app(Ledger::class)->projectionMismatches())->toBe([]);
});

test('partial cumulative refunds preserve the principal cap after debt collections', function () {
    $user = User::factory()->create();
    $deposit = initializePaymentTest($user);
    fakeVerifiedPayment($deposit);
    app(Deposits::class)->verify($deposit);
    fakeVerifiedRefund($deposit->refresh(), '2201', 200000);
    app(DepositRecovery::class)->refund('2201');
    fakeVerifiedRefund($deposit, '2202', 310000);
    app(DepositRecovery::class)->refund('2202');
    expect(paymentTestDebt($user))->toBe(10000);
    $incoming = initializePaymentTest($user, 100000);
    fakeVerifiedPayment($incoming, ['amount' => 100000, 'fees' => 0]);
    app(Deposits::class)->verify($incoming);
    expect(paymentTestDebt($user))->toBe(0);
    $before = Journal::count();
    fakeVerifiedRefund($deposit, '2203', 1);

    app(DepositRecovery::class)->refund('2203');

    expect(Journal::count())->toBe($before);
    expect(PaymentReversal::where('deposit_id', $deposit->id)->sum('principal'))->toBe(500000);
    expect(PaymentReversal::where('deposit_id', $deposit->id)->sum('fee'))->toBe(10000);
    expect(PaymentException::where('code', 'refund_cap_exceeded')->exists())->toBeTrue();
});

test('reversed unrecognized payments cannot consume unrelated wallet money or create principal debt', function () {
    $user = User::factory()->create();
    postWalletTestCredit($user, 'unrelated', 100000);
    $deposit = Deposit::factory()->for($user)->create();
    $deposit->forceFill(['provider_id' => (string) ($deposit->id + 1000)])->save();
    configurePaymentTest();
    fakeVerifiedPayment($deposit, ['status' => 'reversed']);
    fakeVerifiedRefund($deposit, '2301', 510000);

    app(DepositRecovery::class)->refund('2301');

    expect(app(Wallets::class)->provision($user)->balance->kobo)->toBe(-100000);
    expect(paymentTestDebt($user))->toBe(0);
    expect((string) $deposit->fresh()->state)->toBe('reversed');
    expect(PaymentReversal::sole()->journal_id)->toBeNull();
    expect(PaymentReversal::sole()->evidence['id'])->toBe('2301');
    $this->assertDatabaseCount('journals', 1);
});

test('verified system recovery works after deactivation or verification loss while spending stays denied', function (string $condition) {
    $user = User::factory()->create();
    $deposit = initializePaymentTest($user);
    fakeVerifiedPayment($deposit);
    app(Deposits::class)->verify($deposit);
    $user->forceFill($condition === 'inactive' ? ['is_active' => false] : ['email_verified_at' => null])->save();
    fakeVerifiedRefund($deposit->refresh(), '2401', 500000);
    expect(fn () => spendPaymentTestWallet($user, 1, 'forbidden-spend'))->toThrow(DomainException::class);

    app(DepositRecovery::class)->refund('2401');

    expect(app(Wallets::class)->provision($user)->balance->kobo)->toBe(0);
    expect(paymentTestDebt($user))->toBe(0);
    expect(PaymentReversal::sole()->wallet_collected)->toBe(500000);
})->with(['inactive', 'unverified']);

test('uncertain or partial provider deductions stay in exceptions without a guessed recovery', function (array $changes) {
    $user = User::factory()->create();
    $deposit = initializePaymentTest($user);
    fakeVerifiedPayment($deposit);
    app(Deposits::class)->verify($deposit);
    fakeVerifiedRefund($deposit->refresh(), '2501', 500000, $changes);

    app(DepositRecovery::class)->refund('2501');

    $this->assertDatabaseCount('payment_reversals', 0);
    expect(app(Wallets::class)->provision($user)->balance->kobo)->toBe(-500000);
    expect(PaymentException::where('code', 'refund_evidence_required')->exists())->toBeTrue();
})->with(['missing deduction' => [['deducted_amount' => null]], 'partial deduction' => [['deducted_amount' => 100000]], 'pending refund' => [['status' => 'pending']], 'wrong currency' => [['currency' => 'USD']]]);

test('seeded payment spending and reversal sequences preserve balances provenance and projections', function (int $seed) {
    $random = new Randomizer(new Mt19937($seed));
    $user = User::factory()->create();
    $available = 0;
    $debt = 0;
    $remaining = [];
    for ($step = 0; $step < 12; $step++) {
        $choice = $random->getInt(0, 2);
        if ($choice === 1 && $available > 0) {
            $spent = $random->getInt(1, $available);
            spendPaymentTestWallet($user, $spent, 'sequence-spend-'.$step);
            $available -= $spent;
        } elseif ($choice === 2 && $remaining !== []) {
            $id = array_key_first($remaining);
            $refund = $random->getInt(1, $remaining[$id]);
            $deposit = Deposit::findOrFail($id);
            fakeVerifiedRefund($deposit, (string) (9000 + $step), $refund);
            app(DepositRecovery::class)->refund((string) (9000 + $step));
            $collected = min($available, $refund);
            $available -= $collected;
            $debt += $refund - $collected;
            $remaining[$id] -= $refund;
            if ($remaining[$id] === 0) {
                unset($remaining[$id]);
            }
        } else {
            $net = $random->getInt(100000, 900000);
            $deposit = initializePaymentTest($user, $net);
            fakeVerifiedPayment($deposit, ['amount' => $net, 'fees' => 0]);
            app(Deposits::class)->verify($deposit);
            $repaid = min($debt, $net);
            $debt -= $repaid;
            $available += $net - $repaid;
            $remaining[$deposit->id] = $net;
        }
        expect(app(Wallets::class)->provision($user)->balance->kobo)->toBe(-$available);
        expect(paymentTestDebt($user))->toBe($debt);
        expect(app(Ledger::class)->projectionMismatches())->toBe([]);
        foreach (Journal::with('entries')->get() as $journal) {
            $sum = BigInteger::zero();
            foreach ($journal->entries as $entry) {
                $sum = $sum->plus($entry->amount->kobo);
            }
            expect((string) $sum)->toBe('0');
            expect($journal->entries->count())->toBeGreaterThanOrEqual(2);
        }
    }
})->with([2718, 31415]);

test('dispute and refund observations share one economic effect and preserve the provider deadline', function () {
    $deposit = initializePaymentTest(User::factory()->create());
    fakeVerifiedPayment($deposit);
    app(Deposits::class)->verify($deposit);
    fakeVerifiedRefund($deposit->refresh(), '2701', 500000, ['dispute' => '3701']);
    Http::fake([
        'api.paystack.co/dispute/3701' => Http::response(['status' => true, 'data' => [
            'id' => '3701', 'domain' => 'test', 'transaction' => ['id' => $deposit->provider_id], 'status' => 'resolved',
            'resolution' => 'merchant-accepted', 'refund_amount' => 500000, 'dueAt' => '2026-11-05T06:00:00Z',
        ]]),
        'api.paystack.co/refund?*' => Http::response(['status' => true, 'data' => [['id' => '2701']]]),
    ]);

    app(DepositRecovery::class)->dispute('3701');
    app(DepositRecovery::class)->refund('2701');

    $this->assertDatabaseCount('payment_reversals', 1);
    expect(PaymentException::where('code', 'provider_dispute')->sole()->resolved_at)->not->toBeNull();
    expect(PaymentException::where('code', 'provider_dispute')->sole()->response_due_at->toIso8601String())->toBe('2026-11-05T06:00:00+00:00');
    $this->assertDatabaseCount('journals', 3);
});
