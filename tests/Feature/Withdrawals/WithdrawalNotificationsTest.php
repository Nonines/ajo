<?php

use App\Contracts\Clock;
use App\Jobs\DeliverPaymentNotice;
use App\Jobs\DispatchWithdrawal;
use App\Models\LedgerAccount;
use App\Models\PaymentNotice;
use App\Models\SavedDestination;
use App\Models\User;
use App\Notifications\WithdrawalStatusNotice;
use App\Support\Clock\FrozenClock;
use App\Support\Ledger\Ledger;
use App\Support\Money\Money;
use App\Support\Payments\PaymentNotices;
use App\Support\Withdrawals\Withdrawals;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function (): void {
    app()->instance(Clock::class, new FrozenClock(CarbonImmutable::parse('2026-11-07T10:00:00Z')));
    config(['payments.secret_key' => 'sk_test_fixture']);
});

test('withdrawal email distinguishes unknown fees from exact monetary amounts', function (): void {
    $notice = new WithdrawalStatusNotice(['type' => 'withdrawal_paid', 'reference' => 'ajo-test', 'withdrawal_id' => '1',
        'principal' => '100001', 'reserved_fee' => '0', 'actual_fee' => 'unverified', 'remaining_debt' => '1']);

    $mail = $notice->toMail(new stdClass);

    expect($mail->introLines)->toContain('Principal: NGN 1000.01', 'Reserved Fee: NGN 0.00',
        'Actual Fee: awaiting verification', 'Remaining Debt: NGN 0.01');
});

test('withdrawal notices retain encrypted intent and deliver an idempotent in-app record', function (): void {
    Queue::fake([DispatchWithdrawal::class, DeliverPaymentNotice::class]);
    Notification::fake();
    $withdrawal = requestedPayout();
    fakePayoutOutcome($withdrawal);
    app(Withdrawals::class)->review(payoutStaff(), $withdrawal, true, 'review');
    app(Withdrawals::class)->dispatch($withdrawal);
    app(Withdrawals::class)->verify($withdrawal);
    $notice = PaymentNotice::where('withdrawal_request_id', $withdrawal->id)->where('data', '!=', '')->latest('created_at')->orderByDesc('id')->get()
        ->first(fn (PaymentNotice $notice): bool => $notice->data['type'] === 'withdrawal_paid');

    app(PaymentNotices::class)->deliver($notice->id);
    app(PaymentNotices::class)->deliver($notice->id);

    $this->assertDatabaseCount('payment_notices', 3);
    $this->assertDatabaseCount('notifications', 1);
    Notification::assertSentOnDemandTimes(WithdrawalStatusNotice::class, 1);
    expect(DB::table('payment_notices')->where('id', $notice->id)->sole()->recipient)->not->toBe($withdrawal->user->email);
});

test('a notice persistence failure rolls back withdrawal reservation and all its evidence', function (): void {
    $user = User::factory()->create();
    $destination = SavedDestination::factory()->for($user)->create();
    postWalletTestCredit($user, kobo: 101000);
    DB::unprepared("CREATE TRIGGER reject_notice BEFORE INSERT ON payment_notices BEGIN SELECT RAISE(ABORT, 'fixture failure'); END");

    expect(fn () => app(Withdrawals::class)->request($user, $destination, new Money(100000), (string) Str::uuid()))->toThrow(QueryException::class);

    $this->assertDatabaseCount('withdrawal_requests', 0);
    $this->assertDatabaseCount('journals', 1);
    $this->assertDatabaseCount('activity_log', 1);
    expect(app(Ledger::class)->balance(LedgerAccount::where('code', 'customer:'.$user->id.':wallet')->sole())->kobo)->toBe(-101000);
    expect(app(Ledger::class)->projectionMismatches())->toBe([]);
});
