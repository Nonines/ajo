<?php

use App\Contracts\Clock;
use App\Jobs\DispatchWithdrawal;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Support\Approvals;
use App\Support\FrozenClock;
use App\Support\Ledger;
use App\Support\Money;
use App\Support\SavingsProcessing;
use App\Support\Wallets;
use App\Support\Withdrawals;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    app()->instance(Clock::class, new FrozenClock(CarbonImmutable::parse('2026-11-07T10:00:00Z')));
});

test('a distinct authorized checker applies a large payout atomically', function (): void {
    Queue::fake([DispatchWithdrawal::class]);
    $withdrawal = requestedPayout(10000000);
    $approval = app(Approvals::class)->propose(payoutStaff(), 'withdrawal', $withdrawal, [], 'Checked destination');
    expect((string) $withdrawal->fresh()->state)->toBe('under_review');

    app(Approvals::class)->decide(payoutStaff(), $approval, true, 'Checked reservation');

    expect($approval->fresh()->state)->toBe('approved');
    expect($approval->fresh()->applied_at)->not->toBeNull();
    expect((string) $withdrawal->fresh()->state)->toBe('queued_for_payout');
    $this->assertDatabaseCount('approval_requests', 1);
});

test('late maker activity never extends the original customer deadline', function (): void {
    $clock = app(Clock::class);
    $withdrawal = requestedPayout(10000000);
    $clock->set($clock->now()->addHours(23));
    $approval = app(Approvals::class)->propose(payoutStaff(), 'withdrawal', $withdrawal, [], 'Maker review');
    expect($approval->expires_at->equalTo($withdrawal->expires_at))->toBeTrue();
    $clock->set($clock->now()->addHour());

    app(Approvals::class)->decide(payoutStaff(), $approval, true, 'Checker review');

    expect($approval->fresh()->state)->toBe('expired');
    expect((string) $withdrawal->fresh()->state)->toBe('cancelled');
    expect(app(Ledger::class)->balance(LedgerAccount::where('code', 'clearing:payouts_in_transit')->sole())->kobo)->toBe(0);
});

test('database checks reject self approval even through raw updates', function (): void {
    $withdrawal = requestedPayout(10000000);
    $maker = payoutStaff('admin');
    $approval = app(Approvals::class)->propose($maker, 'withdrawal', $withdrawal, [], 'Maker');

    expect(fn () => DB::table('approval_requests')->where('id', $approval->id)->update(['decided_by' => $maker->id]))
        ->toThrow(QueryException::class, 'must differ');

    expect($approval->fresh()->state)->toBe('pending');
});

test('customer cancellation invalidates stale proposals without releasing twice', function (): void {
    $withdrawal = requestedPayout(10000000);
    $approval = app(Approvals::class)->propose(payoutStaff(), 'withdrawal', $withdrawal, [], 'Maker');
    $user = User::findOrFail($withdrawal->user_id);
    app(Withdrawals::class)->cancel($user, $withdrawal);

    expect(fn () => app(Approvals::class)->decide(payoutStaff(), $approval, true, 'Checker'))->toThrow(DomainException::class);
    app(Withdrawals::class)->cancel($user, $withdrawal);

    expect($approval->fresh()->state)->toBe('cancelled');
    expect(app(Ledger::class)->balance(LedgerAccount::where('code', 'clearing:payouts_in_transit')->sole())->kobo)->toBe(0);
});

test('permission loss by the maker invalidates checker application', function (): void {
    $withdrawal = requestedPayout(10000000);
    $maker = payoutStaff();
    $approval = app(Approvals::class)->propose($maker, 'withdrawal', $withdrawal, [], 'Maker');
    $maker->forceFill(['is_active' => false])->save();

    expect(fn () => app(Approvals::class)->decide(payoutStaff(), $approval, true, 'Checker'))->toThrow(AuthorizationException::class);

    expect($approval->fresh()->state)->toBe('pending');
    expect((string) $withdrawal->fresh()->state)->toBe('under_review');
});

test('recovery release requires zero debt and writeoff requires two authorized staff', function (): void {
    $withdrawal = requestedPayout();
    $user = User::findOrFail($withdrawal->user_id);
    $debt = LedgerAccount::where('code', 'customer:'.$user->id.':reversal_receivable')->sole();
    app(Ledger::class)->post('debt-fixture', [['account_id' => $debt->id, 'amount' => new Money(1000)],
        ['account_id' => LedgerAccount::where('code', 'house:cash_at_gateway')->sole()->id, 'amount' => new Money(-1000)]], '2026-11-07', 'Debt fixture', $user->id);
    $release = app(Approvals::class)->propose(payoutStaff(), 'recovery.release', $user, [], 'Review repayment');
    expect($release->created_at->addHours(24)->equalTo($release->expires_at))->toBeTrue();

    expect(fn () => app(Approvals::class)->decide(payoutStaff(), $release, true, 'Release'))->toThrow(DomainException::class, 'zero debt');
    $writeoff = app(Approvals::class)->propose(payoutStaff('admin'), 'recovery.write-off', $user, ['amount' => '1000'], 'Writeoff');
    app(Approvals::class)->decide(payoutStaff('admin'), $writeoff, true, 'Approve writeoff');
    app(Approvals::class)->decide(payoutStaff(), $release, true, 'Debt cleared');

    expect(app(Ledger::class)->balance($debt)->kobo)->toBe(0);
    expect($release->fresh()->state)->toBe('approved');
});

test('two staff plan holds preserve accrual and approved release settles overdue maturity', function (): void {
    $plan = fundedAccrualPlan();
    $maker = payoutStaff();
    $checker = payoutStaff();
    $hold = app(Approvals::class)->propose($maker, 'plan.hold', $plan, [], 'Review financial records');
    expect((string) $plan->fresh()->state)->toBe('active');
    app(Approvals::class)->decide($checker, $hold, true, 'Hold confirmed');
    expect((string) $plan->fresh()->state)->toBe('suspended');
    app(Clock::class)->set($plan->matures_at->addDay());
    app(SavingsProcessing::class)->process($plan->id);
    expect((string) $plan->fresh()->state)->toBe('suspended');
    $release = app(Approvals::class)->propose($maker, 'plan.release', $plan->refresh(), [], 'Review complete');

    app(Approvals::class)->decide($checker, $release, true, 'Release confirmed');
    app(SavingsProcessing::class)->process($plan->id);

    expect((string) $plan->fresh()->state)->toBe('closed');
    expect($plan->fresh()->closure_reason)->toBe('matured_withdrawal');
    expect(DB::table('plan_accruals')->where('savings_plan_id', $plan->id)->count())->toBe(30);
    expect(app(Ledger::class)->projectionMismatches())->toBe([]);
});

test('approved manual credits repay recovery before exposing a wallet remainder and cannot apply twice', function (): void {
    $user = User::factory()->create();
    $wallet = app(Wallets::class)->provision($user);
    $debt = LedgerAccount::where('code', 'customer:'.$user->id.':reversal_receivable')->sole();
    $cash = LedgerAccount::where('code', 'house:cash_at_gateway')->sole();
    app(Ledger::class)->post('manual-fixture-debt', [['account_id' => $debt->id, 'amount' => new Money(2000)],
        ['account_id' => $cash->id, 'amount' => new Money(-2000)]], '2026-11-07', 'Recovery fixture', $user->id);
    $proposal = app(Approvals::class)->propose(payoutStaff(), 'adjustment', $user,
        ['effective_date' => '2026-11-07', 'entries' => [
            ['account_id' => $wallet->id, 'amount' => '-3000'], ['account_id' => $cash->id, 'amount' => '3000'],
        ]], 'Correct verified incoming value');
    expect(app(Ledger::class)->balance($debt)->kobo)->toBe(2000);
    expect(app(Ledger::class)->balance($wallet)->kobo)->toBe(0);
    $checker = payoutStaff();

    app(Approvals::class)->decide($checker, $proposal, true, 'Evidence checked');

    expect(app(Ledger::class)->balance($debt)->kobo)->toBe(0);
    expect(app(Ledger::class)->balance($wallet)->kobo)->toBe(-1000);
    expect(fn () => app(Approvals::class)->decide($checker, $proposal, true, 'Replay'))->toThrow(DomainException::class);
    expect(app(Ledger::class)->projectionMismatches())->toBe([]);
});
