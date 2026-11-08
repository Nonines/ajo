<?php

use App\Contracts\Clock;
use App\Jobs\DeliverPaymentNotice;
use App\Jobs\DispatchWithdrawal;
use App\Jobs\VerifyWithdrawal;
use App\Models\LedgerAccount;
use App\Models\PaymentException;
use App\Models\SavedDestination;
use App\Models\User;
use App\Support\Approvals;
use App\Support\DepositRecovery;
use App\Support\Deposits;
use App\Support\FrozenClock;
use App\Support\Ledger;
use App\Support\Money;
use App\Support\Withdrawals;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function (): void {
    app()->instance(Clock::class, new FrozenClock(CarbonImmutable::parse('2026-11-07T10:00:00Z')));
    config(['payments.secret_key' => 'sk_test_fixture']);
});

test('a verified reversal cancels approved unsubmitted withdrawals and released value repays debt', function (): void {
    $user = User::factory()->create();
    $deposit = initializePaymentTest($user);
    fakeVerifiedPayment($deposit);
    app(Deposits::class)->verify($deposit);
    Queue::fake([DispatchWithdrawal::class, DeliverPaymentNotice::class]);
    $destination = SavedDestination::factory()->for($user)->create();
    $withdrawal = app(Withdrawals::class)->request($user, $destination, new Money(499000), (string) Str::uuid());
    app(Withdrawals::class)->review(payoutStaff(), $withdrawal, true, 'review');
    fakeVerifiedRefund($deposit->refresh(), '501', 500000);

    app(DepositRecovery::class)->refund('501');
    app(DepositRecovery::class)->refund('501');
    app(Withdrawals::class)->dispatch($withdrawal);

    expect((string) $withdrawal->fresh()->state)->toBe('cancelled');
    expect(app(Ledger::class)->balance(LedgerAccount::where('code', 'customer:'.$user->id.':reversal_receivable')->sole())->kobo)->toBe(0);
    expect(app(Ledger::class)->balance(LedgerAccount::where('code', 'customer:'.$user->id.':wallet')->sole())->kobo)->toBe(0);
    expect(app(Ledger::class)->balance(LedgerAccount::where('code', 'clearing:payouts_in_transit')->sole())->kobo)->toBe(0);
});

test('unknown submission retains reservations and retry reuses the exact original reference', function (): void {
    Queue::fake([DispatchWithdrawal::class, DeliverPaymentNotice::class]);
    $withdrawal = requestedPayout();
    $clock = app(Clock::class);
    app(Withdrawals::class)->review(payoutStaff(), $withdrawal, true, 'review');
    Http::preventStrayRequests();
    $offline = true;
    $sent = false;
    $data = ['id' => 50001, 'reference' => $withdrawal->reference, 'amount' => $withdrawal->principal,
        'domain' => 'test', 'currency' => 'NGN', 'status' => 'success', 'fee_charged' => 1000,
        'recipient' => ['recipient_code' => $withdrawal->destination['recipient_code']]];
    Http::fake([
        'api.paystack.co/transfer' => function ($request) use (&$offline, &$sent, $withdrawal) {
            if ($offline) {
                return (Http::failedConnection())($request);
            }
            $sent = true;
            expect($request['reference'])->toBe($withdrawal->reference);
            expect(app(Withdrawals::class)->providerApproval($request->data()))->toBeTrue();

            return Http::response(['status' => true, 'data' => []]);
        },
        'api.paystack.co/transfer/verify/'.$withdrawal->reference => function () use (&$sent, $data) {
            return $sent ? Http::response(['status' => true, 'data' => $data]) : Http::response(['status' => false], 404);
        },
        'api.paystack.co/transfer/50001' => Http::response(['status' => true, 'data' => $data]),
    ]);

    app(Withdrawals::class)->dispatch($withdrawal);
    expect($withdrawal->fresh()->submitted_at)->not->toBeNull();
    expect(app(Ledger::class)->balance(LedgerAccount::where('code', 'clearing:payouts_in_transit')->sole())->kobo)->toBe(-101000);
    $offline = false;
    $clock->set($clock->now()->addMinutes(15));

    app(Withdrawals::class)->reconcile($withdrawal);
    app(Withdrawals::class)->dispatch($withdrawal);

    expect((string) $withdrawal->fresh()->state)->toBe('paid');
    expect($withdrawal->fresh()->reference)->toBe($data['reference']);
    expect(app(Ledger::class)->projectionMismatches())->toBe([]);
});

test('an expired maker proposal cancels at the boundary without restarting the deadline', function (): void {
    $withdrawal = requestedPayout(10000000);
    $clock = app(Clock::class);
    $clock->set($clock->now()->addHours(24));

    $proposal = app(Approvals::class)->propose(payoutStaff(), 'withdrawal', $withdrawal, [], 'Late maker review');

    expect($proposal->state)->toBe('expired');
    expect((string) $withdrawal->fresh()->state)->toBe('cancelled');
    expect(app(Ledger::class)->balance(LedgerAccount::where('code', 'clearing:payouts_in_transit')->sole())->kobo)->toBe(0);
});

test('scheduler recovers unsubmitted jobs and uncertain verification while expiring only pending reviews', function (): void {
    Queue::fake([DispatchWithdrawal::class, VerifyWithdrawal::class, DeliverPaymentNotice::class]);
    $expired = requestedPayout();
    $queued = requestedPayout();
    $uncertain = requestedPayout();
    $staff = payoutStaff();
    app(Withdrawals::class)->review($staff, $queued, true, 'Review');
    app(Withdrawals::class)->review($staff, $uncertain, true, 'Review');
    $uncertain->forceFill(['submitted_at' => app(Clock::class)->now(), 'next_verify_at' => app(Clock::class)->now()->addMinutes(15)])->save();
    app(Clock::class)->set(app(Clock::class)->now()->addHours(25));

    $this->artisan('withdrawals:recover')->assertSuccessful();

    expect((string) $expired->fresh()->state)->toBe('cancelled');
    expect((string) $queued->fresh()->state)->toBe('queued_for_payout');
    expect((string) $uncertain->fresh()->state)->toBe('queued_for_payout');
    Queue::assertPushed(DispatchWithdrawal::class, fn (DispatchWithdrawal $job): bool => $job->withdrawalId === $queued->id);
    Queue::assertPushed(VerifyWithdrawal::class, fn ($job): bool => $job->withdrawalId === $uncertain->id);
    expect(PaymentException::where('code', 'payout_unresolved')->exists())->toBeTrue();
    expect(app(Ledger::class)->balance(LedgerAccount::where('code', 'clearing:payouts_in_transit')->sole())->kobo)->toBe(-202000);
});
