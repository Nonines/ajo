<?php

use App\Contracts\Clock;
use App\Jobs\DeliverSavingsNotice;
use App\Jobs\ProcessSavingsPlan;
use App\Models\LedgerAccount;
use App\Models\SavingsNotice;
use App\Models\SavingsPlan;
use App\Notifications\SavingsMaturityNotice;
use App\Support\FrozenClock;
use App\Support\Ledger;
use App\Support\Money;
use App\Support\SavingsAccrual;
use App\Support\SavingsNotices;
use App\Support\SavingsProcessing;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    app()->instance(Clock::class, new FrozenClock(CarbonImmutable::parse('2028-02-28T00:00:00Z')));
    config(['inertia.ssr.enabled' => false]);
});

test('bounded processing resumes after interruption and cannot settle before final accrual', function () {
    Queue::fake([DeliverSavingsNotice::class]);
    $plan = fundedAccrualPlan();
    config(['savings.processing_days' => 7]);
    app(Clock::class)->set($plan->matures_at);
    $processing = app(SavingsProcessing::class);
    $processing->process($plan->id);
    $this->assertDatabaseCount('plan_accruals', 7);
    expect(fn () => app(SavingsAccrual::class)->settle($plan))->toThrow(DomainException::class, 'every contractual');
    for ($run = 0; $run < 4; $run++) {
        $processing->process($plan->id);
    }

    $this->assertDatabaseCount('plan_accruals', 30);
    $this->assertDatabaseCount('plan_settlements', 1);
    expect(app(Ledger::class)->projectionMismatches())->toBe([]);
});

test('minute discovery requeues due work without consuming durable eligibility', function () {
    Queue::fake([ProcessSavingsPlan::class, DeliverSavingsNotice::class]);
    $plan = fundedAccrualPlan();
    app(Clock::class)->set($plan->matures_at);

    $this->artisan('savings:recover')->assertSuccessful();
    $this->artisan('savings:recover')->assertSuccessful();

    Queue::assertPushed(ProcessSavingsPlan::class, 2);
    Queue::assertPushed(ProcessSavingsPlan::class, fn ($job) => $job->planId === $plan->id && $job->queue === 'accrual');
    expect($plan->fresh()->next_process_at->lessThan(app(Clock::class)->now()))->toBeTrue();
    $this->assertDatabaseCount('plan_accruals', 0);
});

test('notice failure preserves committed finance and retry delivers one inbox record', function () {
    Queue::fake([DeliverSavingsNotice::class]);
    $plan = fundedAccrualPlan();
    app(Clock::class)->set($plan->matures_at);
    app(SavingsProcessing::class)->process($plan->id);
    $notice = SavingsNotice::sole();
    Notification::shouldReceive('send')->once()->andThrow(new RuntimeException('Mail outage'));

    expect(fn () => app(SavingsNotices::class)->deliver($notice->id))->toThrow(RuntimeException::class, 'Mail outage');
    $this->assertDatabaseCount('plan_settlements', 1);
    $this->assertDatabaseCount('notifications', 1);
    expect($notice->fresh()->mail_sent_at)->toBeNull();
    Notification::fake();
    app(SavingsNotices::class)->deliver($notice->id);
    app(SavingsNotices::class)->deliver($notice->id);

    Notification::assertSentOnDemand(SavingsMaturityNotice::class);
    $this->assertDatabaseCount('notifications', 1);
    expect($notice->fresh()->mail_sent_at)->not->toBeNull();
    $this->actingAs($plan->user)->get(route('notifications.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Notifications')->where('notifications.data.0.data.type', 'savings_maturity'));
    $this->get(route('savings.show', $plan))->assertOk()->assertInertia(fn (Assert $page) => $page->where('statement.gross_interest', '8219'));
});

test('accrual write failures roll back journal projection and dated evidence together', function () {
    $plan = fundedAccrualPlan();
    app(Clock::class)->set($plan->accrual_starts_at->addDay());
    DB::unprepared("CREATE TRIGGER fail_accrual BEFORE INSERT ON plan_accruals BEGIN SELECT RAISE(ABORT, 'Interrupted write'); END");

    expect(fn () => app(SavingsAccrual::class)->date($plan, '2028-02-29'))->toThrow(QueryException::class);

    $this->assertDatabaseCount('plan_accruals', 0);
    $this->assertDatabaseCount('journals', 2);
    expect(app(Ledger::class)->projectionMismatches())->toBe([]);
    DB::unprepared('DROP TRIGGER fail_accrual');
    expect(app(SavingsAccrual::class)->date($plan, '2028-02-29'))->toBeTrue();
    $this->assertDatabaseCount('plan_accruals', 1);
});

test('dated command replays chronological ranges without repeating financial effects', function () {
    $plan = fundedAccrualPlan();
    app(Clock::class)->set($plan->accrual_starts_at->addDays(3));
    $arguments = ['--plan' => $plan->id, '--from' => '2028-02-29', '--through' => '2028-03-02'];

    $this->artisan('savings:process', $arguments)->assertSuccessful();
    $this->artisan('savings:process', $arguments)->assertSuccessful();

    $this->assertDatabaseCount('plan_accruals', 3);
    $this->assertDatabaseCount('journals', 5);
});

test('a dispatch outage does not prevent another due plan from being discovered', function () {
    $first = fundedAccrualPlan();
    $second = fundedAccrualPlan();
    app(Clock::class)->set($first->matures_at);
    $seen = [];
    Bus::partialMock()->shouldReceive('dispatch')->twice()->andReturnUsing(function ($job) use (&$seen, $first) {
        $seen[] = $job->planId;
        if ($job->planId === $first->id) {
            throw new RuntimeException('Redis dispatch unavailable');
        }

        return null;
    });

    app(SavingsProcessing::class)->discover();

    expect($seen)->toBe([$first->id, $second->id]);
    expect($first->fresh()->next_process_at->lessThan(app(Clock::class)->now()))->toBeTrue();
    expect($second->fresh()->next_process_at->lessThan(app(Clock::class)->now()))->toBeTrue();
});

test('minute recovery expires unfunded contracts without touching wallet funds', function () {
    $plan = SavingsPlan::factory()->create();
    app(Clock::class)->set($plan->funding_deadline);

    $this->artisan('savings:recover')->assertSuccessful();

    expect((string) $plan->fresh()->state)->toBe('cancelled');
    expect($plan->fresh()->closure_reason)->toBe('funding_expired');
    $this->assertDatabaseCount('journals', 0);
});

test('posting overflow creates a durable investigation from both commands and workers', function (string $entry) {
    Queue::fake([DeliverSavingsNotice::class]);
    $plan = fundedAccrualPlan();
    postWalletTestCredit($plan->user, key: 'fill-wallet', kobo: PHP_INT_MAX - 1000000);
    app(Clock::class)->set($plan->matures_at);

    if ($entry === 'command') {
        $this->artisan('savings:process', ['--plan' => $plan->id])->assertFailed();
    } else {
        (new ProcessSavingsPlan($plan->id))->handle(app(SavingsProcessing::class), app(Clock::class));
    }

    $this->assertDatabaseHas('savings_exceptions', ['savings_plan_id' => $plan->id, 'operation' => 'plan:'.$plan->id.':posting-bounds']);
    $this->assertDatabaseCount('plan_settlements', 0);
    $this->assertDatabaseCount('savings_notices', 0);
    expect((string) $plan->fresh()->state)->toBe('active');
    expect(app(Ledger::class)->projectionMismatches())->toBe([]);
})->with(['command', 'worker']);

test('a dated command persists overflow investigation after rolling back its accrual', function () {
    $plan = fundedAccrualPlan();
    app(Ledger::class)->post('expense-at-limit', [
        ['account_id' => LedgerAccount::where('code', 'house:interest_expense')->sole()->id, 'amount' => new Money(PHP_INT_MAX)],
        ['account_id' => LedgerAccount::where('code', 'house:cash_at_gateway')->sole()->id, 'amount' => new Money(-PHP_INT_MAX)],
    ], '2028-02-28', 'Fixture expense bound');
    app(Clock::class)->set($plan->accrual_starts_at->addDay());

    $this->artisan('savings:process', ['--plan' => $plan->id, '--from' => '2028-02-29'])->assertFailed();

    $this->assertDatabaseHas('savings_exceptions', ['operation' => 'plan:'.$plan->id.':posting-bounds']);
    $this->assertDatabaseCount('plan_accruals', 0);
    expect(app(Ledger::class)->projectionMismatches())->toBe([]);
});
