<?php

use App\Contracts\Clock;
use App\Jobs\DeliverSavingsNotice;
use App\Jobs\PrepareSavingsCalculation;
use App\Models\LedgerAccount;
use App\Models\SavingsPlan;
use App\Support\FrozenClock;
use App\Support\Ledger;
use App\Support\SavingsAccrual;
use App\Support\SavingsPlans;
use App\Support\SavingsProcessing;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    app()->instance(Clock::class, new FrozenClock(CarbonImmutable::parse('2028-02-28T00:00:00Z')));
});

test('daily compounding retains fractions and settles the final boundary without capitalization', function (string $timezone): void {
    config(['app.timezone' => $timezone]);
    app(Clock::class)->set(CarbonImmutable::parse('2028-02-28 10:00:00', $timezone));
    Queue::fake([DeliverSavingsNotice::class]);
    $plan = fundedAccrualPlan(10000, 36500, ['interest' => 'daily']);
    app(Clock::class)->set($plan->matures_at);
    app(SavingsProcessing::class)->process($plan->id);

    $statement = DB::table('plan_settlements')->sole();
    expect($statement->gross_interest)->toBe(3478);
    expect($statement->capitalized_interest)->toBe(3345);
    expect($statement->withholding)->toBe(348);
    expect($statement->wallet_credit)->toBe(13130);
    expect($statement->rounding_micro_kobo)->toBe('-490000');
    $this->assertDatabaseCount('plan_capitalizations', 29);
    $this->assertDatabaseMissing('plan_capitalizations', ['boundary_date' => $plan->matures_at->setTimezone(config('app.timezone'))->toDateString()]);
    expect(app(SavingsAccrual::class)->date($plan, $plan->accrual_starts_at->setTimezone(config('app.timezone'))->toDateString()))->toBeTrue();
    expect(LedgerAccount::where('code', 'house:interest_expense')->sole()->balance->kobo)->toBe(3478);
    expect(app(Ledger::class)->projectionMismatches())->toBe([]);
    Queue::assertPushed(DeliverSavingsNotice::class, 1);
})->with(['UTC', 'America/New_York']);

test('thirty day compounding counts from accrual start through a leap date and bounded catchup', function (string $timezone): void {
    config(['app.timezone' => $timezone]);
    app(Clock::class)->set(CarbonImmutable::parse('2028-02-28 10:00:00', $timezone));
    Queue::fake([DeliverSavingsNotice::class]);
    $plan = fundedAccrualPlan(1000000, 36500, ['interest' => '30_days', 'days' => 60]);
    app(Clock::class)->set($plan->matures_at);
    app(SavingsProcessing::class)->process($plan->id);
    $this->assertDatabaseCount('plan_settlements', 0);
    expect(DB::table('plan_capitalizations')->sole()->boundary_date)->toBe('2028-03-30');
    expect(DB::table('plan_capitalizations')->sole()->amount)->toBe(300000);

    app(SavingsProcessing::class)->process($plan->id);
    app(SavingsProcessing::class)->process($plan->id);

    $statement = DB::table('plan_settlements')->sole();
    expect($statement->gross_interest)->toBe(690000);
    expect($statement->capitalized_interest)->toBe(300000);
    expect($statement->withholding)->toBe(69000);
    expect($statement->wallet_credit)->toBe(1621000);
    $this->assertDatabaseCount('plan_capitalizations', 1);
    $this->assertDatabaseCount('plan_accruals', 60);
    expect(app(Ledger::class)->projectionMismatches())->toBe([]);
})->with(['UTC', 'America/New_York']);

test('zero kobo compounding boundaries keep dated evidence without zero ledger entries', function (): void {
    Queue::fake([DeliverSavingsNotice::class]);
    $plan = fundedAccrualPlan(1, 1000, ['interest' => 'daily']);
    app(Clock::class)->set($plan->matures_at);
    app(SavingsProcessing::class)->process($plan->id);

    $this->assertDatabaseCount('plan_capitalizations', 29);
    expect(DB::table('plan_capitalizations')->whereNotNull('journal_id')->exists())->toBeFalse();
    expect(DB::table('plan_settlements')->sole()->wallet_credit)->toBe(1);
    expect(DB::table('plan_settlements')->sole()->rounding_micro_kobo)->toBe('-8220');
    expect(DB::table('ledger_entries')->where('amount', 0)->exists())->toBeFalse();
});

test('capitalization interruption rolls back the dated accrual journal and projections together', function (): void {
    $plan = fundedAccrualPlan(10000, 36500, ['interest' => 'daily']);
    app(Clock::class)->set($plan->accrual_starts_at->addDay());
    $date = $plan->accrual_starts_at->setTimezone(config('app.timezone'))->toDateString();
    DB::unprepared("CREATE TRIGGER fail_capitalization BEFORE INSERT ON plan_capitalizations BEGIN SELECT RAISE(ABORT, 'Interrupted compounding'); END");
    expect(fn () => app(SavingsAccrual::class)->date($plan, $date))->toThrow(QueryException::class, 'Interrupted compounding');
    $this->assertDatabaseCount('plan_accruals', 0);
    $this->assertDatabaseCount('plan_capitalizations', 0);
    $this->assertDatabaseCount('journals', 2);
    DB::unprepared('DROP TRIGGER fail_capitalization');
    expect(app(SavingsAccrual::class)->date($plan, $date))->toBeTrue();
    expect(app(SavingsAccrual::class)->date($plan, $date))->toBeTrue();
    $this->assertDatabaseCount('plan_capitalizations', 1);
    expect(app(Ledger::class)->projectionMismatches())->toBe([]);
});

test('compounding evidence cannot be changed or replaced through direct database writes', function (string $operation): void {
    $plan = fundedAccrualPlan(10000, 36500, ['interest' => 'daily']);
    app(Clock::class)->set($plan->accrual_starts_at->addDay());
    app(SavingsAccrual::class)->catchUp($plan);
    $row = DB::table('plan_capitalizations')->sole();

    expect(fn () => match ($operation) {
        'update' => DB::table('plan_capitalizations')->where('id', $row->id)->update(['amount' => 1]),
        'delete' => DB::table('plan_capitalizations')->where('id', $row->id)->delete(),
        'replace' => DB::statement('INSERT OR REPLACE INTO plan_capitalizations (id, savings_plan_id, boundary_date, amount, cumulative_capitalized, posted_at) VALUES (?, ?, ?, 1, 1, ?)', [$row->id, $plan->id, $row->boundary_date, $row->posted_at]),
    })->toThrow(QueryException::class, 'immutable');
})->with(['update', 'delete', 'replace']);

test('a corrupted capitalization blocks replay and retains all savings liabilities', function (): void {
    Queue::fake([DeliverSavingsNotice::class]);
    $plan = fundedAccrualPlan(10000, 36500, ['interest' => 'daily']);
    app(Clock::class)->set($plan->accrual_starts_at->addDays(3));
    app(SavingsAccrual::class)->catchUp($plan);
    DB::unprepared('DROP TRIGGER plan_capitalizations_no_UPDATE');
    DB::table('plan_capitalizations')->where('id', 1)->update(['amount' => 101]);

    expect(app(SavingsAccrual::class)->date($plan, $plan->accrual_starts_at->setTimezone(config('app.timezone'))->toDateString()))->toBeFalse();
    app(Clock::class)->set($plan->matures_at);
    app(SavingsProcessing::class)->process($plan->id);
    $this->assertDatabaseCount('savings_exceptions', 1);
    $this->assertDatabaseCount('plan_settlements', 0);
    expect((string) $plan->fresh()->state)->toBe('active');
});

test('funding rejects compounded exposure that would fit under a simple interest estimate', function (): void {
    $plan = SavingsPlan::factory()->create(['contract' => ['principal' => '7000000000000000000',
        'annual_rate_bp' => 36500, 'days' => 30, 'interest' => 'daily']]);
    postWalletTestCredit($plan->user, kobo: 7000000000000000000);
    app(SavingsPlans::class)->accept($plan->user, $plan);

    expect(fn () => app(SavingsPlans::class)->fund($plan->user, $plan))->toThrow(DomainException::class, 'full contractual entitlement');
    expect($plan->fresh()->funding_journal_id)->toBeNull();
    $this->assertDatabaseCount('journals', 1);
});

test('entitlement preparation commits bounded immutable steps and resumes to the same exact result', function (): void {
    Queue::fake([PrepareSavingsCalculation::class]);
    config(['savings.preparation_steps' => 3]);
    $contract = ['principal' => '10000', 'annual_rate_bp' => 36500, 'days' => 30, 'interest' => 'daily'];
    expect(app(SavingsAccrual::class)->entitlement($contract, 30))->toBeNull();
    expect(DB::table('savings_calculation_steps')->sole()->completed_days)->toBe(3);
    $fingerprint = DB::table('savings_calculations')->sole()->fingerprint;
    for ($run = 0; $run < 9; $run++) {
        (new PrepareSavingsCalculation($fingerprint))->handle(app(SavingsAccrual::class));
    }
    expect(app(SavingsAccrual::class)->entitlement($contract, 30))->toBe(['micro' => '3478490000', 'recognized' => 3478, 'capitalized' => 3345]);
    $this->assertDatabaseCount('savings_calculation_steps', 10);
    $this->assertDatabaseCount('journals', 0);
    expect(fn () => DB::table('savings_calculation_steps')->where('completed_days', 3)->update(['recognized' => 1]))->toThrow(QueryException::class, 'immutable');
    Queue::assertPushed(PrepareSavingsCalculation::class);
});

test('funding waits for preparation without reserving principal or extending its deadline', function (): void {
    Queue::fake([PrepareSavingsCalculation::class]);
    config(['savings.preparation_steps' => 3]);
    $plan = SavingsPlan::factory()->create(['contract' => ['principal' => '10000', 'annual_rate_bp' => 36500,
        'withholding_bp' => 1000, 'days' => 30, 'interest' => 'daily']]);
    postWalletTestCredit($plan->user, kobo: 10000);
    $deadline = $plan->funding_deadline;
    app(SavingsPlans::class)->accept($plan->user, $plan);
    app(SavingsPlans::class)->fund($plan->user, $plan);
    expect((string) $plan->fresh()->state)->toBe('pending_funding');
    expect($plan->fresh()->funding_journal_id)->toBeNull();
    expect($plan->fresh()->funding_deadline->equalTo($deadline))->toBeTrue();
    $this->assertDatabaseCount('journals', 1);
    $fingerprint = DB::table('savings_calculations')->sole()->fingerprint;
    while (! app(SavingsAccrual::class)->prepareCalculation($fingerprint)) {
    }
    app(SavingsPlans::class)->fund($plan->user, $plan);
    expect((string) $plan->fresh()->state)->toBe('active');
    $this->assertDatabaseCount('journals', 2);
});

test('large zero rate daily terms finish without iterating over every future day', function (): void {
    $result = app(SavingsAccrual::class)->entitlement(['principal' => '1', 'annual_rate_bp' => 0, 'days' => 1200000, 'interest' => 'daily'], 1200000);
    expect($result)->toBe(['micro' => '0', 'recognized' => 0, 'capitalized' => 0]);
    expect(DB::table('savings_calculation_steps')->sole()->completed_days)->toBe(1200000);
    $this->assertDatabaseCount('savings_calculation_steps', 1);
});

test('discovery resumes interrupted calculation preparation without duplicating committed steps', function (): void {
    Queue::fake([PrepareSavingsCalculation::class]);
    config(['savings.preparation_steps' => 3]);
    $contract = ['principal' => '10000', 'annual_rate_bp' => 36500, 'days' => 30, 'interest' => 'daily'];
    app(SavingsAccrual::class)->entitlement($contract, 30);
    $fingerprint = DB::table('savings_calculations')->sole()->fingerprint;
    DB::unprepared("CREATE TRIGGER fail_preparation BEFORE INSERT ON savings_calculation_steps BEGIN SELECT RAISE(ABORT, 'Interrupted preparation'); END");
    expect(fn () => app(SavingsAccrual::class)->prepareCalculation($fingerprint))->toThrow(QueryException::class, 'Interrupted preparation');
    $this->assertDatabaseCount('savings_calculation_steps', 1);
    expect(DB::table('savings_calculations')->sole()->completed_at)->toBeNull();
    DB::unprepared('DROP TRIGGER fail_preparation');
    app(SavingsProcessing::class)->discover();
    Queue::assertPushed(PrepareSavingsCalculation::class, fn ($job): bool => $job->fingerprint === $fingerprint);
    for ($run = 0; $run < 9; $run++) {
        (new PrepareSavingsCalculation($fingerprint))->handle(app(SavingsAccrual::class));
    }
    expect(app(SavingsAccrual::class)->entitlement($contract, 30)['recognized'])->toBe(3478);
    $this->assertDatabaseCount('savings_calculation_steps', 10);
    $this->assertDatabaseCount('journals', 0);
});
