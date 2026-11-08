<?php

use App\Contracts\Clock;
use App\Jobs\DeliverSavingsNotice;
use App\Models\LedgerAccount;
use App\Models\SavingsPlan;
use App\Support\FrozenClock;
use App\Support\Ledger;
use App\Support\Money;
use App\Support\PlanStates\Active;
use App\Support\PlanStates\Suspended;
use App\Support\SavingsAccrual;
use App\Support\SavingsPlans;
use App\Support\SavingsProcessing;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    app()->instance(Clock::class, new FrozenClock(CarbonImmutable::parse('2028-02-28T00:00:00Z')));
});

test('simple accrual earns 30 completed application days across leap day and settles tax once', function () {
    Queue::fake([DeliverSavingsNotice::class]);
    $plan = fundedAccrualPlan();
    app(Clock::class)->set($plan->matures_at);

    app(SavingsProcessing::class)->process($plan->id);
    app(SavingsProcessing::class)->process($plan->id);

    $statement = DB::table('plan_settlements')->sole();
    expect($statement->principal)->toBe(1000000);
    expect($statement->gross_interest)->toBe(8219);
    expect($statement->withholding)->toBe(822);
    expect($statement->net_interest)->toBe(7397);
    expect($statement->remaining_debt)->toBe(0);
    expect($statement->wallet_credit)->toBe(1007397);
    expect($statement->rounding_micro_kobo)->toBe('-178090');
    expect((string) $plan->fresh()->state)->toBe('closed');
    $this->assertDatabaseCount('plan_accruals', 30);
    $this->assertDatabaseHas('plan_accruals', ['accrual_date' => '2028-02-29']);
    $this->assertDatabaseCount('plan_settlements', 1);
    $this->assertDatabaseCount('savings_notices', 1);
    expect(LedgerAccount::where('code', 'house:interest_expense')->sole()->balance->kobo)->toBe(8219);
    expect(LedgerAccount::where('code', 'house:withholding_tax_payable')->sole()->balance->kobo)->toBe(-822);
    expect(app(Ledger::class)->projectionMismatches())->toBe([]);
});

test('processing waits for a completed day in the application timezone', function (string $timezone, string $fundedAt, string $boundary, string $accrualDate) {
    config(['app.timezone' => $timezone]);
    app(Clock::class)->set(CarbonImmutable::parse($fundedAt));
    $plan = fundedAccrualPlan();
    $clock = app(Clock::class);
    $clock->set(CarbonImmutable::parse($boundary)->subSecond());

    app(SavingsProcessing::class)->process($plan->id);

    $this->assertDatabaseCount('plan_accruals', 0);
    expect(app(SavingsAccrual::class)->completedDays($plan))->toBe(0);
    $clock->set(CarbonImmutable::parse($boundary));

    app(SavingsProcessing::class)->process($plan->id);

    $this->assertDatabaseHas('plan_accruals', ['savings_plan_id' => $plan->id, 'accrual_date' => $accrualDate]);
    $this->assertDatabaseCount('plan_accruals', 1);
    expect(app(SavingsAccrual::class)->completedDays($plan))->toBe(1);
})->with([
    'default UTC' => ['UTC', '2028-02-27T23:30:00Z', '2028-02-29T00:00:00Z', '2028-02-28'],
    'configured timezone' => ['Asia/Kolkata', '2028-02-27T23:30:00Z', '2028-02-29T18:30:00Z', '2028-02-29'],
]);

test('completed days and catchup count application dates across spring forward', function () {
    config(['app.timezone' => 'America/New_York']);
    app(Clock::class)->set(CarbonImmutable::parse('2027-03-12T17:00:00Z'));
    $plan = fundedAccrualPlan();
    app(Clock::class)->set(CarbonImmutable::parse('2027-03-15T04:00:00Z'));
    $accrual = app(SavingsAccrual::class);

    expect($accrual->completedDays($plan))->toBe(2);
    expect($accrual->catchUp($plan))->toBeTrue();

    $this->assertDatabaseCount('plan_accruals', 2);
    $this->assertDatabaseHas('plan_accruals', ['savings_plan_id' => $plan->id, 'accrual_date' => '2027-03-13']);
    $this->assertDatabaseHas('plan_accruals', ['savings_plan_id' => $plan->id, 'accrual_date' => '2027-03-14']);
});

test('daily micro precision and cumulative kobo use half even ties', function () {
    $accrual = app(SavingsAccrual::class);
    expect($accrual->dailyMicro('1', 73))->toBe('20');
    expect($accrual->rounded('500000'))->toBe(0);
    expect($accrual->rounded('1500000'))->toBe(2);
    expect($accrual->rounded('2500000'))->toBe(2);
    expect($accrual->dailyMicro('1', 1))->toBe('0');
});

test('zero and small daily interest carry fractions without zero journals', function (int $principal, int $rate, int $gross) {
    Queue::fake([DeliverSavingsNotice::class]);
    $plan = fundedAccrualPlan($principal, $rate);
    app(Clock::class)->set($plan->matures_at);

    app(SavingsProcessing::class)->process($plan->id);

    expect(DB::table('plan_settlements')->sole()->gross_interest)->toBe($gross);
    expect(DB::table('ledger_entries')->where('amount', 0)->exists())->toBeFalse();
    $this->assertDatabaseCount('plan_accruals', 30);
    expect(app(Ledger::class)->projectionMismatches())->toBe([]);
})->with([[1, 0, 0], [1, 1000, 0], [100, 1000, 1]]);

test('accrual refuses incomplete future and out of order days and replays have no financial effect', function () {
    $plan = fundedAccrualPlan();
    $accrual = app(SavingsAccrual::class);
    $first = $plan->accrual_starts_at->setTimezone(config('app.timezone'))->toDateString();
    expect(fn () => $accrual->date($plan, $first))->toThrow(DomainException::class);
    app(Clock::class)->set($plan->accrual_starts_at->addDays(3));
    expect(fn () => $accrual->date($plan, '2028-03-01'))->toThrow(DomainException::class);
    expect($accrual->date($plan, $first))->toBeTrue();
    expect($accrual->date($plan, $first))->toBeTrue();
    $this->assertDatabaseCount('plan_accruals', 1);
    $this->assertDatabaseCount('journals', 3);
});

test('mismatched replay commits investigation and blocks maturity on subsequent retries', function () {
    Queue::fake([DeliverSavingsNotice::class]);
    $plan = fundedAccrualPlan();
    app(Clock::class)->set($plan->matures_at);
    for ($date = $plan->accrual_starts_at->setTimezone(config('app.timezone')); $date->lessThan($plan->matures_at); $date = $date->addDay()) {
        app(SavingsAccrual::class)->date($plan, $date->toDateString());
    }
    DB::unprepared('DROP TRIGGER plan_accruals_no_UPDATE');
    DB::table('plan_accruals')->where('accrual_date', '2028-03-29')->update(['fingerprint' => str_repeat('0', 64)]);
    DB::unprepared("CREATE TRIGGER plan_accruals_no_UPDATE BEFORE UPDATE ON plan_accruals BEGIN SELECT RAISE(ABORT, 'Savings evidence is immutable'); END");

    expect(app(SavingsAccrual::class)->settle($plan))->toBeFalse();
    app(SavingsProcessing::class)->process($plan->id);

    $this->assertDatabaseCount('savings_exceptions', 1);
    $this->assertDatabaseCount('plan_settlements', 0);
    expect((string) $plan->fresh()->state)->toBe('active');
});

test('a suspended plan earns through original maturity and settles only after release', function () {
    Queue::fake([DeliverSavingsNotice::class]);
    $plan = fundedAccrualPlan();
    $plan->state->transitionTo(Suspended::class);
    app(Clock::class)->set($plan->matures_at->addDays(10));
    app(SavingsProcessing::class)->process($plan->id);
    $this->assertDatabaseCount('plan_accruals', 30);
    $this->assertDatabaseCount('plan_settlements', 0);
    expect((string) $plan->fresh()->state)->toBe('suspended');

    $plan->refresh()->state->transitionTo(Active::class);
    app(SavingsProcessing::class)->process($plan->id);
    expect(DB::table('plan_settlements')->sole()->gross_interest)->toBe(8219);
    expect(app(Ledger::class)->projectionMismatches())->toBe([]);
});

test('maturity proceeds repay reversal debt before exposing wallet funds', function (int $debt, int $wallet, int $remaining) {
    Queue::fake([DeliverSavingsNotice::class]);
    $plan = fundedAccrualPlan();
    $receivable = LedgerAccount::where('code', 'customer:'.$plan->user_id.':reversal_receivable')->sole();
    app(Ledger::class)->post('recovery', [['account_id' => $receivable->id, 'amount' => new Money($debt)],
        ['account_id' => LedgerAccount::where('code', 'house:cash_at_gateway')->sole()->id, 'amount' => new Money(-$debt)]], '2028-02-28', 'Fixture reversal', $plan->user_id);
    app(Clock::class)->set($plan->matures_at);

    app(SavingsProcessing::class)->process($plan->id);

    expect(DB::table('plan_settlements')->sole()->wallet_credit)->toBe($wallet);
    expect($receivable->fresh()->balance->kobo)->toBe($remaining);
    expect(DB::table('plan_settlements')->sole()->remaining_debt)->toBe($remaining);
    expect(app(Ledger::class)->projectionMismatches())->toBe([]);
})->with([[500000, 507397, 0], [2000000, 0, 992603]]);

test('overflowing full entitlement is rejected before principal is locked', function () {
    $plan = SavingsPlan::factory()->create(['contract' => ['principal' => (string) PHP_INT_MAX, 'annual_rate_bp' => 1000, 'days' => 30]]);
    postWalletTestCredit($plan->user, kobo: PHP_INT_MAX);
    app(SavingsPlans::class)->accept($plan->user, $plan);

    expect(fn () => app(SavingsPlans::class)->fund($plan->user, $plan))->toThrow(DomainException::class, 'full contractual entitlement');
    expect($plan->fresh()->funding_journal_id)->toBeNull();
    $this->assertDatabaseCount('journals', 1);
});

test('micro kobo larger than native integers remain exact decimal strings', function () {
    $plan = fundedAccrualPlan(100000000000000000, 1000);
    app(Clock::class)->set($plan->accrual_starts_at->addDay());
    app(SavingsAccrual::class)->date($plan, $plan->accrual_starts_at->setTimezone(config('app.timezone'))->toDateString());

    expect(DB::table('plan_accruals')->sole()->micro_kobo)->toBe('27397260273972602740');
    expect(app(Ledger::class)->projectionMismatches())->toBe([]);
});

test('seeded interleaved plan operations preserve balances interest and idempotency', function (int $seed) {
    Queue::fake([DeliverSavingsNotice::class]);
    mt_srand($seed);
    $plans = [];
    $principals = [];
    for ($index = 0; $index < 3; $index++) {
        $principal = mt_rand(100, 10000) * 100;
        $plans[] = fundedAccrualPlan($principal, 36500);
        $principals[] = $principal;
    }
    app(Clock::class)->set($plans[0]->matures_at);
    $progress = [0, 0, 0];
    while (min($progress) < 30) {
        $index = mt_rand(0, 2);
        if ($progress[$index] >= 30) {
            continue;
        }
        $date = $plans[$index]->accrual_starts_at->setTimezone(config('app.timezone'))->addDays($progress[$index])->toDateString();
        expect(app(SavingsAccrual::class)->date($plans[$index], $date))->toBeTrue();
        if (mt_rand(0, 1) === 1) {
            expect(app(SavingsAccrual::class)->date($plans[$index], $date))->toBeTrue();
        }
        $progress[$index]++;
        expect(app(Ledger::class)->projectionMismatches())->toBe([]);
    }
    foreach ($plans as $index => $plan) {
        expect(app(SavingsAccrual::class)->settle($plan))->toBeTrue();
        expect(app(SavingsAccrual::class)->settle($plan))->toBeTrue();
        $statement = DB::table('plan_settlements')->where('savings_plan_id', $plan->id)->sole();
        expect($statement->gross_interest)->toBe(intdiv($principals[$index] * 30, 100));
        expect($statement->wallet_credit)->toBe(intdiv($principals[$index] * 127, 100));
    }
    foreach (DB::table('journals')->get() as $journal) {
        expect(DB::table('ledger_entries')->where('journal_id', $journal->id)->sum('amount'))->toBe(0);
        expect(DB::table('ledger_entries')->where('journal_id', $journal->id)->count())->toBeGreaterThanOrEqual(2);
    }
    expect(LedgerAccount::where('code', 'house:interest_expense')->sole()->balance->kobo)->toBe(DB::table('plan_settlements')->sum('gross_interest'));
    expect(app(Ledger::class)->projectionMismatches())->toBe([]);
})->with([17, 90210]);

test('settlement failure leaves all liabilities intact and retries exactly once', function () {
    Queue::fake([DeliverSavingsNotice::class]);
    $plan = fundedAccrualPlan();
    app(Clock::class)->set($plan->matures_at);
    DB::unprepared("CREATE TRIGGER fail_settlement BEFORE INSERT ON plan_settlements BEGIN SELECT RAISE(ABORT, 'Interrupted settlement'); END");

    expect(fn () => app(SavingsProcessing::class)->process($plan->id))->toThrow(QueryException::class, 'Interrupted settlement');

    $this->assertDatabaseCount('plan_accruals', 30);
    $this->assertDatabaseCount('plan_settlements', 0);
    $this->assertDatabaseCount('savings_notices', 0);
    $this->assertDatabaseMissing('journals', ['operation_key' => 'plan-settlement:'.$plan->id]);
    expect((string) $plan->fresh()->state)->toBe('active');
    expect(app(Ledger::class)->projectionMismatches())->toBe([]);
    DB::unprepared('DROP TRIGGER fail_settlement');
    app(SavingsProcessing::class)->process($plan->id);
    $this->assertDatabaseCount('plan_settlements', 1);
});

test('historical replay mismatch and invalid coverage both prevent settlement', function (string $fault) {
    Queue::fake([DeliverSavingsNotice::class]);
    $plan = fundedAccrualPlan();
    app(Clock::class)->set($plan->matures_at);
    app(SavingsProcessing::class)->process($plan->id, '2028-03-28');
    app(SavingsAccrual::class)->date($plan, '2028-03-29');
    DB::unprepared('DROP TRIGGER plan_accruals_no_UPDATE');
    DB::unprepared('DROP TRIGGER plan_accruals_no_DELETE');
    match ($fault) {
        'replay' => DB::table('plan_accruals')->where('accrual_date', '2028-02-29')->update(['fingerprint' => str_repeat('0', 64)]),
        'outside-range' => DB::table('plan_accruals')->where('accrual_date', '2028-02-29')->update(['accrual_date' => '2028-02-28']),
        'missing' => DB::table('plan_accruals')->where('accrual_date', '2028-03-10')->delete(),
    };
    DB::unprepared("CREATE TRIGGER plan_accruals_no_UPDATE BEFORE UPDATE ON plan_accruals BEGIN SELECT RAISE(ABORT, 'Savings evidence is immutable'); END");
    DB::unprepared("CREATE TRIGGER plan_accruals_no_DELETE BEFORE DELETE ON plan_accruals BEGIN SELECT RAISE(ABORT, 'Savings evidence is immutable'); END");

    if ($fault === 'replay') {
        expect(app(SavingsAccrual::class)->date($plan, '2028-02-29'))->toBeFalse();
    }
    if ($fault === 'missing') {
        expect(fn () => app(SavingsAccrual::class)->settle($plan))->toThrow(DomainException::class, 'every contractual');
    } else {
        expect(app(SavingsAccrual::class)->settle($plan))->toBeFalse();
        $this->assertDatabaseCount('savings_exceptions', 1);
    }
    $this->assertDatabaseCount('plan_settlements', 0);
    $this->assertDatabaseMissing('journals', ['operation_key' => 'plan-settlement:'.$plan->id]);
})->with(['replay', 'outside-range', 'missing']);
