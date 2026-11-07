<?php

use App\Contracts\Clock;
use App\Jobs\DispatchWithdrawal;
use App\Jobs\VerifyWithdrawal;
use App\Models\LedgerAccount;
use App\Models\PaymentException;
use App\Models\SavedDestination;
use App\Models\User;
use App\Support\Approvals;
use App\Support\AuditTrail;
use App\Support\FrozenClock;
use App\Support\Ledger;
use App\Support\Money;
use App\Support\Withdrawals;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    app()->instance(Clock::class, new FrozenClock(CarbonImmutable::parse('2026-11-07T10:00:00Z')));
    config(['payments.secret_key' => 'sk_test_fixture']);
});

test('withdrawal reserves principal plus fee once and binds review to the whole reservation', function (int $principal, bool $checker): void {
    $withdrawal = requestedPayout($principal);
    $user = User::findOrFail($withdrawal->user_id);

    $again = app(Withdrawals::class)->request($user, SavedDestination::findOrFail($withdrawal->saved_destination_id), new Money($principal), $withdrawal->request_key);

    expect($again->id)->toBe($withdrawal->id);
    expect(app(Withdrawals::class)->requiresChecker($withdrawal))->toBe($checker);
    expect(app(Ledger::class)->balance(LedgerAccount::where('code', 'customer:'.$user->id.':wallet')->sole())->kobo)->toBe(0);
    expect(app(Ledger::class)->projectionMismatches())->toBe([]);
    expect(app(AuditTrail::class)->verify())->toBe(3);
})->with([[9995000, false], [9995001, true], [10000000, true]]);

test('one staff review queues an eligible small reservation and server approved verification pays it once', function (): void {
    Queue::fake([DispatchWithdrawal::class, VerifyWithdrawal::class]);
    $withdrawal = requestedPayout(100000, 10000);
    fakePayoutOutcome($withdrawal);
    app(Withdrawals::class)->review(payoutStaff(), $withdrawal, true, 'Reviewed destination and amount');

    app(Withdrawals::class)->dispatch($withdrawal);
    app(Withdrawals::class)->dispatch($withdrawal);
    app(Withdrawals::class)->verify($withdrawal);

    expect((string) $withdrawal->fresh()->state)->toBe('paid');
    expect($withdrawal->fresh()->accounted_fee)->toBe(1000);
    expect(app(Ledger::class)->balance(LedgerAccount::where('code', 'clearing:payouts_in_transit')->sole())->kobo)->toBe(0);
    expect(app(Ledger::class)->projectionMismatches())->toBe([]);
    Http::assertSentCount(5);
});

test('large reservations cannot be approved by a single reviewer or self approved by an admin', function (): void {
    $withdrawal = requestedPayout(10000000);
    $admin = payoutStaff('admin');

    expect(fn () => app(Withdrawals::class)->review($admin, $withdrawal, true, 'review'))->toThrow(DomainException::class, 'different checker');
    $approval = app(Approvals::class)->propose($admin, 'withdrawal', $withdrawal, [], 'Ready for checker');
    expect(fn () => app(Approvals::class)->decide($admin, $approval, true, 'approve'))->toThrow(AuthorizationException::class);

    expect((string) $withdrawal->fresh()->state)->toBe('under_review');
    $this->assertDatabaseCount('approval_requests', 1);
});

test('review expiry cancels and releases funds at the original deadline', function (): void {
    $clock = app(Clock::class);
    $withdrawal = requestedPayout();
    $staff = payoutStaff();
    $clock->set($clock->now()->addHours(24));

    app(Withdrawals::class)->review($staff, $withdrawal, true, 'review');

    expect((string) $withdrawal->fresh()->state)->toBe('cancelled');
    expect(app(Ledger::class)->balance(LedgerAccount::where('code', 'customer:'.$withdrawal->user_id.':wallet')->sole())->kobo)->toBe(-101000);
    expect(app(Ledger::class)->balance(LedgerAccount::where('code', 'clearing:payouts_in_transit')->sole())->kobo)->toBe(0);
});

test('verified higher fees leave payout paid collect available funds and book only the shortfall', function (): void {
    Queue::fake([DispatchWithdrawal::class]);
    $withdrawal = requestedPayout(100000, 100);
    fakePayoutOutcome($withdrawal, fee: 1500);
    app(Withdrawals::class)->review(payoutStaff(), $withdrawal, true, 'review');

    app(Withdrawals::class)->dispatch($withdrawal);
    app(Withdrawals::class)->verify($withdrawal);

    expect((string) $withdrawal->fresh()->state)->toBe('paid');
    expect(app(Ledger::class)->balance(LedgerAccount::where('code', 'customer:'.$withdrawal->user_id.':wallet')->sole())->kobo)->toBe(0);
    expect(app(Ledger::class)->balance(LedgerAccount::where('code', 'customer:'.$withdrawal->user_id.':reversal_receivable')->sole())->kobo)->toBe(400);
    expect(PaymentException::where('code', 'payout_fee_excess')->exists())->toBeTrue();
    expect(app(Ledger::class)->projectionMismatches())->toBe([]);
});

test('confirmed failed payout returns principal and unused fee but retains an incurred fee', function (): void {
    Queue::fake([DispatchWithdrawal::class]);
    $withdrawal = requestedPayout();
    fakePayoutOutcome($withdrawal, 'failed', 400);
    app(Withdrawals::class)->review(payoutStaff(), $withdrawal, true, 'review');

    app(Withdrawals::class)->dispatch($withdrawal);
    app(Withdrawals::class)->verify($withdrawal);

    expect((string) $withdrawal->fresh()->state)->toBe('failed');
    expect(app(Ledger::class)->balance(LedgerAccount::where('code', 'customer:'.$withdrawal->user_id.':wallet')->sole())->kobo)->toBe(-100600);
    expect(app(Ledger::class)->balance(LedgerAccount::where('code', 'clearing:payouts_in_transit')->sole())->kobo)->toBe(0);
});

test('post success reversal returns principal once and retains actual fees', function (): void {
    Queue::fake([DispatchWithdrawal::class]);
    $withdrawal = requestedPayout();
    fakePayoutOutcome($withdrawal);
    app(Withdrawals::class)->review(payoutStaff(), $withdrawal, true, 'review');
    app(Withdrawals::class)->dispatch($withdrawal);
    config(['payout_fixture.'.$withdrawal->id.'.status' => 'reversed']);

    app(Withdrawals::class)->verify($withdrawal);
    app(Withdrawals::class)->verify($withdrawal);

    expect((string) $withdrawal->fresh()->state)->toBe('reversed');
    expect(app(Ledger::class)->balance(LedgerAccount::where('code', 'customer:'.$withdrawal->user_id.':wallet')->sole())->kobo)->toBe(-100000);
    expect(app(Ledger::class)->projectionMismatches())->toBe([]);
});

test('fee increases before dispatch require a new request and make no provider call', function (): void {
    Queue::fake([DispatchWithdrawal::class]);
    Http::preventStrayRequests();
    $withdrawal = requestedPayout();
    app(Withdrawals::class)->review(payoutStaff(), $withdrawal, true, 'review');
    config(['withdrawals.fee_tiers' => [[PHP_INT_MAX, 2000]]]);

    app(Withdrawals::class)->dispatch($withdrawal);

    expect((string) $withdrawal->fresh()->state)->toBe('cancelled');
    Http::assertNothingSent();
});

test('unknown actual fees retain only the fee reservation after a verified payment', function (): void {
    Queue::fake([DispatchWithdrawal::class]);
    $withdrawal = requestedPayout();
    fakePayoutOutcome($withdrawal, fee: null);
    app(Withdrawals::class)->review(payoutStaff(), $withdrawal, true, 'review');

    app(Withdrawals::class)->dispatch($withdrawal);

    expect((string) $withdrawal->fresh()->state)->toBe('paid');
    expect(app(Ledger::class)->balance(LedgerAccount::where('code', 'clearing:payouts_in_transit')->sole())->kobo)->toBe(-1000);
    expect(PaymentException::where('code', 'payout_fee_unknown')->exists())->toBeTrue();
});
