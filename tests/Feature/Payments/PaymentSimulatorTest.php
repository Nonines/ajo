<?php

use App\Contracts\Clock;
use App\Jobs\DeliverPaymentNotice;
use App\Jobs\DispatchWithdrawal;
use App\Jobs\ProcessPaymentWebhook;
use App\Models\Deposit;
use App\Models\LedgerAccount;
use App\Models\PaymentReversal;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Models\WithdrawalRequest;
use App\Support\Deposits;
use App\Support\FrozenClock;
use App\Support\Ledger;
use App\Support\Money;
use App\Support\PaymentInbox;
use App\Support\PaymentSimulator;
use App\Support\Withdrawals;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    app()->instance(Clock::class, new FrozenClock(CarbonImmutable::parse('2026-11-07T10:00:00Z')));
    config(['withdrawals.simulation_enabled' => true, 'withdrawals.simulation_signing_key' => 'test-only-simulator-signing-key-at-least-32-bytes']);
});

test('signed simulated deposit scenarios share the durable inbox and verification boundary', function (string $scenario): void {
    Queue::fake([ProcessPaymentWebhook::class, DeliverPaymentNotice::class]);
    Http::preventStrayRequests();
    $admin = payoutStaff('admin');
    $user = User::factory()->create();
    $simulation = app(PaymentSimulator::class)->create($admin, $user, 'deposit', new Money(100000));

    app(PaymentSimulator::class)->observe($admin, $simulation, $scenario);
    foreach (WebhookEvent::orderBy('id')->get() as $event) {
        app(PaymentInbox::class)->process($event->id);
    }
    if ($scenario === 'missing') {
        app(Deposits::class)->verify(Deposit::findOrFail($simulation->subject_id));
    }

    expect((string) Deposit::findOrFail($simulation->subject_id)->state)->toBe($scenario === 'failure' ? 'failed' : 'confirmed');
    expect(app(Ledger::class)->balance(LedgerAccount::where('code', 'customer:'.$user->id.':wallet')->sole())->kobo)->toBe($scenario === 'failure' ? 0 : -100000);
    expect(WebhookEvent::count())->toBe(match ($scenario) {
        'missing' => 0, 'reordered' => 2, default => 1
    });
    expect(app(Ledger::class)->projectionMismatches())->toBe([]);
    Http::assertNothingSent();
})->with(['success', 'failure', 'duplicate', 'reordered', 'missing']);

test('simulated post recognition reversals recover through the ordinary verified refund operation', function (): void {
    Queue::fake([ProcessPaymentWebhook::class, DeliverPaymentNotice::class]);
    Http::preventStrayRequests();
    $admin = payoutStaff('admin');
    $user = User::factory()->create();
    $simulation = app(PaymentSimulator::class)->create($admin, $user, 'deposit', new Money(100000));
    app(PaymentSimulator::class)->observe($admin, $simulation, 'success');
    app(PaymentInbox::class)->process(WebhookEvent::sole()->id);

    app(PaymentSimulator::class)->observe($admin, $simulation, 'reversal');
    app(PaymentInbox::class)->process(WebhookEvent::orderByDesc('id')->firstOrFail()->id);

    expect((string) Deposit::findOrFail($simulation->subject_id)->state)->toBe('reversed');
    expect(app(Ledger::class)->balance(LedgerAccount::where('code', 'customer:'.$user->id.':wallet')->sole())->kobo)->toBe(0);
    expect(app(Ledger::class)->balance(LedgerAccount::where('code', 'customer:'.$user->id.':reversal_receivable')->sole())->kobo)->toBe(1000);
    Http::assertNothingSent();
});

test('simulated payout still requires ordinary review dispatch and server approval', function (): void {
    Queue::fake([DispatchWithdrawal::class, DeliverPaymentNotice::class, ProcessPaymentWebhook::class]);
    Http::preventStrayRequests();
    $admin = payoutStaff('admin');
    $user = User::factory()->create();
    postWalletTestCredit($user, kobo: 101000);
    $simulation = app(PaymentSimulator::class)->create($admin, $user, 'withdrawal', new Money(100000));
    $withdrawal = WithdrawalRequest::findOrFail($simulation->subject_id);
    expect(fn () => app(PaymentSimulator::class)->observe($admin, $simulation, 'success'))->toThrow(DomainException::class, 'ordinary staff review');
    app(Withdrawals::class)->review(payoutStaff(), $withdrawal, true, 'Reviewed simulation');
    app(Withdrawals::class)->dispatch($withdrawal);

    app(PaymentSimulator::class)->observe($admin, $simulation, 'success');
    app(PaymentInbox::class)->process(WebhookEvent::sole()->id);

    expect((string) $withdrawal->fresh()->state)->toBe('paid');
    expect($withdrawal->fresh()->provider_approved_at)->not->toBeNull();
    Http::assertNothingSent();
});

test('real and simulated deposits use their own adapters within the same worker', function (): void {
    $user = User::factory()->create();
    $real = initializePaymentTest($user);
    fakeVerifiedPayment($real);
    $admin = payoutStaff('admin');
    $simulation = app(PaymentSimulator::class)->create($admin, $user, 'deposit', new Money(100000));
    app(PaymentSimulator::class)->observe($admin, $simulation, 'success');

    app(PaymentInbox::class)->process(WebhookEvent::sole()->id);
    app(Deposits::class)->verify($real);

    expect((string) $real->fresh()->state)->toBe('confirmed');
    expect((string) Deposit::findOrFail($simulation->subject_id)->state)->toBe('confirmed');
    Http::assertSentCount(1);
    expect(app(Ledger::class)->balance(LedgerAccount::where('code', 'customer:'.$user->id.':wallet')->sole())->kobo)->toBe(-600000);
});

test('a simulated inbox event cannot route a real reference through simulated verification', function (): void {
    $real = initializePaymentTest(User::factory()->create());
    fakeVerifiedPayment($real);
    $raw = json_encode(['event' => 'charge.success', 'data' => ['reference' => $real->reference]], JSON_THROW_ON_ERROR);
    $event = app(PaymentInbox::class)->receiveSimulated($raw, hash_hmac('sha512', $raw, config('withdrawals.simulation_signing_key')));

    app(PaymentInbox::class)->process($event->id);

    expect($real->fresh()->recognition_journal_id)->toBeNull();
    expect($event->fresh()->processed_at)->toBeNull();
    Http::assertNothingSent();
});

test('simulation permission and environment gates cannot be bypassed by a manager', function (): void {
    $manager = payoutStaff();
    $user = User::factory()->create();

    expect(fn () => app(PaymentSimulator::class)->create($manager, $user, 'deposit', new Money(100000)))->toThrow(AuthorizationException::class);
    config(['withdrawals.simulation_enabled' => false]);
    expect(fn () => app(PaymentSimulator::class)->create(payoutStaff('admin'), $user, 'deposit', new Money(100000)))->toThrow(AuthorizationException::class);
});

test('identical provider resource IDs in different origins cannot redirect recognition or refund recovery', function (): void {
    $user = User::factory()->create();
    $real = initializePaymentTest($user);
    $admin = payoutStaff('admin');
    $simulation = app(PaymentSimulator::class)->create($admin, $user, 'deposit', new Money(100000));
    fakeVerifiedPayment($real, ['id' => $simulation->evidence['id']]);
    app(Deposits::class)->verify($real);
    app(PaymentSimulator::class)->observe($admin, $simulation, 'success');
    app(PaymentInbox::class)->process(WebhookEvent::sole()->id);
    app(PaymentSimulator::class)->observe($admin, $simulation, 'reversal');
    $refundId = $simulation->refresh()->refund_id;
    fakeVerifiedRefund($real->refresh(), $refundId, 500000);
    $raw = json_encode(['event' => 'refund.processed', 'data' => ['id' => $refundId]], JSON_THROW_ON_ERROR);
    $event = app(PaymentInbox::class)->receive($raw, hash_hmac('sha512', $raw, 'sk_test_fixture'));

    app(PaymentInbox::class)->process($event->id);
    foreach (WebhookEvent::whereNull('processed_at')->get() as $pending) {
        app(PaymentInbox::class)->process($pending->id);
    }

    $this->assertDatabaseCount('payment_reversals', 2);
    expect(PaymentReversal::where('origin', 'real')->sole()->deposit_id)->toBe($real->id);
    expect(PaymentReversal::where('origin', 'simulated')->sole()->deposit_id)->toBe($simulation->subject_id);
    expect(app(Ledger::class)->balance(LedgerAccount::where('code', 'customer:'.$user->id.':wallet')->sole())->kobo)->toBe(0);
    expect(app(Ledger::class)->balance(LedgerAccount::where('code', 'customer:'.$user->id.':reversal_receivable')->sole())->kobo)->toBe(1000);
    expect(app(Ledger::class)->projectionMismatches())->toBe([]);
});
