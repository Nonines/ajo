<?php

use App\Contracts\Clock;
use App\Jobs\DeliverPaymentNotice;
use App\Jobs\DeliverSavingsNotice;
use App\Jobs\DispatchWithdrawal;
use App\Jobs\ProcessPaymentWebhook;
use App\Jobs\VerifyWithdrawal;
use App\Models\LedgerAccount;
use App\Models\PaymentReversal;
use App\Models\SavedDestination;
use App\Models\SavingsProductVersion;
use App\Models\User;
use App\Support\AuditTrail;
use App\Support\DepositRecovery;
use App\Support\Deposits;
use App\Support\FrozenClock;
use App\Support\Ledger;
use App\Support\Money;
use App\Support\RecoveryControls;
use App\Support\SavingsPlans;
use App\Support\SavingsProcessing;
use App\Support\Wallets;
use App\Support\Withdrawals;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Random\Engine\Mt19937;
use Random\Randomizer;

test('seeded deposits plans payouts cancellations and reversals preserve the combined financial contracts', function (int $seed): void {
    $clock = new FrozenClock(CarbonImmutable::parse('2026-11-07T10:00:00Z'));
    app()->instance(Clock::class, $clock);
    $random = new Randomizer(new Mt19937($seed));
    $user = User::factory()->create();
    $destination = SavedDestination::factory()->for($user)->create();
    $version = SavingsProductVersion::factory()->create();
    $staff = payoutStaff();
    app(Wallets::class)->provision($user);
    $wallet = LedgerAccount::where('code', 'customer:'.$user->id.':wallet')->sole();
    $plans = [];
    $deposits = [];
    for ($step = 0; $step < 30; $step++) {
        $available = app(Ledger::class)->balance($wallet)->negate()->kobo;
        $debt = app(RecoveryControls::class)->balance($user->id);
        $choice = $step === 0 ? 0 : $random->getInt(0, 5);
        if ($choice === 0) {
            $deposit = initializePaymentTest($user, $random->getInt(100000, 500000));
            fakeVerifiedPayment($deposit);
            Queue::fake([DispatchWithdrawal::class, VerifyWithdrawal::class, DeliverPaymentNotice::class, DeliverSavingsNotice::class, ProcessPaymentWebhook::class]);
            app(Deposits::class)->verify($deposit);
            app(Deposits::class)->verify($deposit);
            $deposits[] = $deposit;
        } elseif ($choice === 1 && $available > 100 && $debt === 0) {
            $plan = app(SavingsPlans::class)->draft($user, $version, new Money($random->getInt(1, $available)), 30, (string) Str::uuid());
            app(SavingsPlans::class)->accept($user, $plan);
            app(SavingsPlans::class)->fund($user, $plan);
            $plans[] = $plan;
        } elseif ($choice === 2 && $plans !== []) {
            $clock->set($clock->now()->addDays(32));
            foreach ($plans as $plan) {
                app(SavingsProcessing::class)->process($plan->id);
            }
        } elseif (in_array($choice, [3, 4], true) && $available > 5000 && $debt === 0) {
            $principal = $random->getInt(1, $available - 5000);
            $withdrawal = app(Withdrawals::class)->request($user, $destination, new Money($principal), (string) Str::uuid());
            if ($choice === 4) {
                app(Withdrawals::class)->cancel($user, $withdrawal);
            } else {
                fakePayoutOutcome($withdrawal, $random->getInt(0, 1) === 1 ? 'success' : 'failed', $random->getInt(0, $withdrawal->reserved_fee));
                app(Withdrawals::class)->review($staff, $withdrawal, true, 'Sequence review');
                app(Withdrawals::class)->dispatch($withdrawal);
                app(Withdrawals::class)->verify($withdrawal);
            }
        } elseif ($choice === 5 && $deposits !== []) {
            $deposit = $deposits[$random->getInt(0, count($deposits) - 1)];
            $remaining = $deposit->requested_net - PaymentReversal::where('deposit_id', $deposit->id)->sum('principal');
            if ($remaining > 0) {
                $id = (string) ($seed * 100 + $step);
                fakeVerifiedPayment($deposit);
                fakeVerifiedRefund($deposit->refresh(), $id, $random->getInt(1, $remaining));
                app(DepositRecovery::class)->refund($id);
                app(DepositRecovery::class)->refund($id);
            }
        }
        expect(app(Ledger::class)->balance($wallet)->kobo)->toBeLessThanOrEqual(0);
        expect(app(RecoveryControls::class)->balance($user->id))->toBeGreaterThanOrEqual(0);
        expect(app(Ledger::class)->projectionMismatches())->toBe([]);
        expect(DB::table('ledger_entries')->groupBy('journal_id')->havingRaw('SUM(amount) != 0 OR COUNT(*) < 2')->count())->toBe(0);
        $expense = app(Ledger::class)->balance(LedgerAccount::where('code', 'house:interest_expense')->sole())->kobo;
        $unpaid = -LedgerAccount::where('code', 'like', 'customer:%:plan:%:interest')->sum('balance');
        expect($expense)->toBe($unpaid + DB::table('plan_settlements')->sum('gross_interest'));
        foreach ($deposits as $deposit) {
            expect(PaymentReversal::where('deposit_id', $deposit->id)->sum('principal'))->toBeLessThanOrEqual($deposit->requested_net);
            $current = $deposit->refresh();
            expect($current->wallet_credit + $current->debt_repayment)->toBe($current->requested_net);
        }
        app(AuditTrail::class)->verify();
    }
})->with([2718, 31415]);
