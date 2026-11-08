<?php

use App\Contracts\Clock;
use App\Jobs\DeliverPaymentNotice;
use App\Jobs\DeliverSavingsNotice;
use App\Jobs\DispatchWithdrawal;
use App\Jobs\ProcessPaymentWebhook;
use App\Jobs\VerifyWithdrawal;
use App\Models\LedgerAccount;
use App\Models\SavedDestination;
use App\Models\SavingsPlan;
use App\Models\User;
use App\Support\Approvals;
use App\Support\AuditTrail;
use App\Support\DepositRecovery;
use App\Support\Deposits;
use App\Support\FrozenClock;
use App\Support\Ledger;
use App\Support\Money;
use App\Support\SavingsAccrual;
use App\Support\SavingsPlans;
use App\Support\SavingsProcessing;
use App\Support\SavingsProducts;
use App\Support\SavingsSettlements;
use App\Support\Withdrawals;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Random\Engine\Mt19937;
use Random\Randomizer;

test('seeded full lifecycle sequences conserve interest principal tax recovery and wallet value after every operation', function (int $seed): void {
    $clock = new FrozenClock(CarbonImmutable::parse('2028-02-27T23:00:00Z'));
    app()->instance(Clock::class, $clock);
    $random = new Randomizer(new Mt19937($seed));
    $verify = function (): void {
        $ledger = app(Ledger::class);
        expect($ledger->projectionMismatches())->toBe([]);
        expect(DB::table('ledger_entries')->groupBy('journal_id')->havingRaw('SUM(amount) != 0 OR COUNT(*) < 2')->count())->toBe(0);
        expect(LedgerAccount::where('code', 'like', 'customer:%:wallet')->where('balance', '>', 0)->exists())->toBeFalse();
        $capitalized = $recognized = 0;
        foreach (SavingsPlan::whereNotNull('funding_journal_id')->get() as $plan) {
            $recognized += (int) DB::table('plan_accruals')->where('savings_plan_id', $plan->id)->max('recognized_kobo');
            $principal = $ledger->balance(app(SavingsPlans::class)->account($plan, 'principal'))->negate()->kobo;
            if ((string) $plan->state === 'closed') {
                expect($principal)->toBe(0);
            } else {
                $capitalized += $principal - (int) $plan->contract['principal'];
            }
        }
        $payable = -LedgerAccount::where('code', 'like', 'customer:%:plan:%:interest')->sum('balance');
        $forfeiture = (int) DB::table('plan_settlements')->sum('forfeiture');
        $settled = (int) DB::table('plan_settlements')->sum('gross_interest') - $forfeiture;
        $expense = $ledger->balance(LedgerAccount::where('code', 'house:interest_expense')->sole())->kobo;
        expect($expense)->toBe($payable + $capitalized + $settled);
        expect($recognized)->toBe($payable + $capitalized + $settled + $forfeiture);
        expect(-$ledger->balance(LedgerAccount::where('code', 'house:withholding_tax_payable')->sole())->kobo)->toBe((int) DB::table('plan_settlements')->sum('withholding'));
        expect(-$ledger->balance(LedgerAccount::where('code', 'house:penalty_income')->sole())->kobo)->toBe((int) DB::table('plan_settlements')->sum('principal_penalty'));
        app(AuditTrail::class)->verify();
    };
    $run = function (Closure $operation) use ($verify): mixed {
        $result = $operation();
        $verify();

        return $result;
    };
    $user = User::factory()->create();
    $deposit = initializePaymentTest($user, 5000000);
    fakeVerifiedPayment($deposit);
    Queue::fake([DeliverPaymentNotice::class, DeliverSavingsNotice::class, DispatchWithdrawal::class, VerifyWithdrawal::class, ProcessPaymentWebhook::class]);
    $run(fn () => app(Deposits::class)->verify($deposit));
    $run(fn () => app(Deposits::class)->verify($deposit));
    $admin = payoutStaff('admin');
    $checker = payoutStaff('admin');
    $plans = [];
    foreach (['maturity', 'exit', 'waived_exit', 'admin', 'rollover'] as $outcome) {
        $mode = ['simple', 'daily', '30_days'][$random->getInt(0, 2)];
        $product = app(SavingsProducts::class)->publish($admin, ['name' => 'Sequence '.$outcome, 'disclosure' => 'Test fixture',
            'minimum' => '1', 'maximum' => '100000000', 'withholding_bp' => 1000,
            'terms' => [['days' => 60, 'annual_rate_bp' => $random->getInt(0, 36500)]], 'interest' => $mode,
            'lock' => 'terminable', 'minimum_holding_days' => 3, 'forfeiture_bp' => $random->getInt(0, 10000), 'principal_penalty_bp' => $random->getInt(0, 1000)]);
        $principal = $random->getInt(10000, 500000);
        $plan = $run(fn () => app(SavingsPlans::class)->draft($user, $product->versions()->sole(), new Money($principal), 60, (string) Str::uuid()));
        $run(fn () => app(SavingsPlans::class)->accept($user, $plan));
        $plans[$outcome] = $run(fn () => app(SavingsPlans::class)->fund($user, $plan));
    }
    $run(fn () => app(SavingsPlans::class)->electRollover($user, $plans['rollover'], true, 60, 'all_net', true));
    $clock->set($plans['exit']->accrual_starts_at->addDays($random->getInt(31, 40))->addHours(12));
    foreach ($random->shuffleArray(['exit', 'waived_exit', 'admin']) as $outcome) {
        $plan = $plans[$outcome];
        while (! $run(fn () => app(SavingsAccrual::class)->catchUp($plan))) {
        }
        $first = $plan->accrual_starts_at->setTimezone('Africa/Lagos')->toDateString();
        $run(fn () => app(SavingsAccrual::class)->date($plan, $first));
        if ($outcome === 'admin') {
            $approval = $run(fn () => app(Approvals::class)->propose($admin, 'plan.close', $plan,
                ['correction' => 'other', 'evidence' => 'Sequence correction'], 'Evidence reviewed'));
            $run(fn () => app(Approvals::class)->decide($checker, $approval, true, 'Independent correction'));
        } else {
            $waiverId = null;
            if ($outcome === 'waived_exit') {
                $approval = $run(fn () => app(Approvals::class)->propose($admin, 'plan.waive', $plan,
                    ['interest_cap' => (string) $random->getInt(1, 5000), 'principal_cap' => (string) $random->getInt(1, 5000)], 'Sequence caps'));
                $run(fn () => app(Approvals::class)->decide($checker, $approval, true, 'Independent caps'));
                $waiverId = DB::table('plan_waivers')->where('savings_plan_id', $plan->id)->sole()->id;
            }
            $quote = $run(fn () => app(SavingsSettlements::class)->preview($user, $plan, $waiverId));
            $run(fn () => app(SavingsSettlements::class)->terminate($user, $plan, $quote['token'], $waiverId));
            $run(fn () => app(SavingsSettlements::class)->terminate($user, $plan, $quote['token'], $waiverId));
        }
    }
    $destination = SavedDestination::factory()->for($user)->create();
    $withdrawal = $run(fn () => app(Withdrawals::class)->request($user, $destination, new Money(100000), (string) Str::uuid()));
    fakePayoutOutcome($withdrawal, 'success');
    $run(fn () => app(Withdrawals::class)->review($admin, $withdrawal, true, 'Sequence payout'));
    $run(fn () => app(Withdrawals::class)->dispatch($withdrawal));
    $run(fn () => app(Withdrawals::class)->verify($withdrawal));
    $cancelled = $run(fn () => app(Withdrawals::class)->request($user, $destination, new Money(10000), (string) Str::uuid()));
    $run(fn () => app(Withdrawals::class)->cancel($user, $cancelled));
    fakeVerifiedPayment($deposit);
    fakeVerifiedRefund($deposit->refresh(), (string) ($seed + 100000), 5000000);
    $run(fn () => app(DepositRecovery::class)->refund((string) ($seed + 100000)));
    $run(fn () => app(DepositRecovery::class)->refund((string) ($seed + 100000)));
    $repayment = initializePaymentTest($user, 5000000);
    fakeVerifiedPayment($repayment);
    Queue::fake([DeliverPaymentNotice::class, DeliverSavingsNotice::class, DispatchWithdrawal::class, VerifyWithdrawal::class, ProcessPaymentWebhook::class]);
    $run(fn () => app(Deposits::class)->verify($repayment));
    $clock->set($plans['maturity']->matures_at->addDays($random->getInt(0, 3)));
    foreach (['maturity', 'rollover'] as $outcome) {
        $run(fn () => app(SavingsProcessing::class)->process($plans[$outcome]->id));
        $run(fn () => app(SavingsProcessing::class)->process($plans[$outcome]->id));
        $run(fn () => app(SavingsProcessing::class)->process($plans[$outcome]->id));
    }
    expect(DB::table('plan_settlements')->distinct()->pluck('closure_reason')->sort()->values()->all())
        ->toBe(['admin_closed', 'early_termination', 'matured_withdrawal', 'rolled_over']);
})->with([2718, 31415]);
