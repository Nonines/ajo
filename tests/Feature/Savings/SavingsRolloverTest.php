<?php

use App\Contracts\Clock;
use App\Jobs\DeliverSavingsNotice;
use App\Jobs\PrepareSavingsCalculation;
use App\Models\LedgerAccount;
use App\Models\SavingsPlan;
use App\Models\SavingsProduct;
use App\Models\SavingsProductVersion;
use App\Models\User;
use App\Support\Auth\Approvals;
use App\Support\Clock\FrozenClock;
use App\Support\Ledger\Ledger;
use App\Support\Money\Money;
use App\Support\Savings\SavingsAccrual;
use App\Support\Savings\SavingsPlans;
use App\Support\Savings\SavingsProcessing;
use App\Support\Savings\SavingsProducts;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    app()->instance(Clock::class, new FrozenClock(CarbonImmutable::parse('2028-02-28T00:00:00Z')));
    config(['inertia.ssr.enabled' => false]);
});

function renewalSavingsPlan(): SavingsPlan
{
    $product = app(SavingsProducts::class)->publish(payoutStaff('admin'), ['name' => 'Renewal fixture', 'disclosure' => 'Fixture terms',
        'minimum' => '1', 'maximum' => '100000000', 'withholding_bp' => 1000,
        'terms' => [['days' => 30, 'annual_rate_bp' => 36500], ['days' => 60, 'annual_rate_bp' => 36500]],
        'interest' => 'simple', 'lock' => 'uncancellable', 'forfeiture_bp' => 0, 'principal_penalty_bp' => 0]);
    $user = User::factory()->create();
    postWalletTestCredit($user, 'renewal-credit:'.$user->id, 10000);
    $plan = app(SavingsPlans::class)->draft($user, $product->versions()->sole(), new Money(10000), 30, (string) Str::uuid());
    app(SavingsPlans::class)->accept($user, $plan);

    return app(SavingsPlans::class)->fund($user, $plan);
}

/** @param array<string, mixed> $changes */
function reviseRenewalProduct(SavingsPlan $plan, array $changes): SavingsProductVersion
{
    $source = SavingsProductVersion::findOrFail($plan->savings_product_version_id);
    $product = SavingsProduct::findOrFail($source->savings_product_id);
    $data = array_replace($product->versions()->where('version', $product->current_version)->sole()->contract, $changes);
    $approval = app(Approvals::class)->propose(payoutStaff('admin'), 'product.rate', $product, ['data' => $data], 'Revise fixture terms');
    app(Approvals::class)->decide(payoutStaff('admin'), $approval, true, 'Terms checked');

    return $product->versions()->orderByDesc('version')->firstOrFail();
}

test('rollover settles withholding then funds a linked child with the chosen amount mode', function (string $mode, int $childPrincipal, int $wallet): void {
    Queue::fake([DeliverSavingsNotice::class]);
    $plan = renewalSavingsPlan();
    app(SavingsPlans::class)->electRollover($plan->user, $plan, true, 30, $mode, true);
    app(Clock::class)->set($plan->matures_at);
    app(SavingsProcessing::class)->process($plan->id);
    app(SavingsProcessing::class)->process($plan->id);
    $child = SavingsPlan::where('parent_plan_id', $plan->id)->sole();
    expect($child->contract['principal'])->toBe((string) $childPrincipal);
    expect($child->rollover_election['enabled'])->toBeTrue();
    expect($child->accrual_starts_at->equalTo($plan->matures_at->addDay()))->toBeTrue();
    expect($child->funded_at->equalTo($plan->matures_at))->toBeTrue();
    expect((string) $child->state)->toBe('active');
    expect($plan->fresh()->closure_reason)->toBe('rolled_over');
    expect(LedgerAccount::where('code', 'customer:'.$plan->user_id.':wallet')->sole()->balance->kobo)->toBe(-$wallet);
    expect(DB::table('plan_settlements')->sole()->withholding)->toBe(300);
    $this->assertDatabaseCount('savings_plans', 2);
    expect(app(Ledger::class)->projectionMismatches())->toBe([]);
    $this->actingAs($plan->user)->get(route('savings.show', $plan))->assertOk()->assertInertia(fn (Assert $page) => $page->where('childPlanId', $child->id));
    $this->get(route('savings.show', $child))->assertOk()->assertInertia(fn (Assert $page) => $page->where('plan.parentPlanId', $plan->id));
})->with([['original_principal', 10000, 2700], ['all_net', 12700, 0]]);

test('delayed rollover selects every term effective at maturity and preserves ordinary stale version rejection', function (): void {
    Queue::fake([DeliverSavingsNotice::class]);
    $plan = renewalSavingsPlan();
    app(SavingsPlans::class)->electRollover($plan->user, $plan, true, 60, 'all_net', true);
    app(Clock::class)->set($plan->matures_at->subHour());
    $effective = reviseRenewalProduct($plan, ['terms' => [['days' => 30, 'annual_rate_bp' => 73000], ['days' => 60, 'annual_rate_bp' => 73000]],
        'interest' => 'daily', 'lock' => 'terminable', 'minimum_holding_days' => 5, 'forfeiture_bp' => 4000, 'principal_penalty_bp' => 100, 'withholding_bp' => 1500]);
    app(Clock::class)->set($plan->matures_at->addDays(2));
    reviseRenewalProduct($plan, ['withholding_bp' => 2000, 'available' => false]);
    app(Clock::class)->set($plan->matures_at->addDays(3)->addHours(10));

    app(SavingsProcessing::class)->process($plan->id);

    $child = SavingsPlan::where('parent_plan_id', $plan->id)->sole();
    expect($child->savings_product_version_id)->toBe($effective->id);
    expect($child->contract['annual_rate_bp'])->toBe(73000);
    expect($child->contract['interest'])->toBe('daily');
    expect($child->contract['lock'])->toBe('terminable');
    expect($child->contract['minimum_holding_days'])->toBe(5);
    expect($child->contract['forfeiture_bp'])->toBe(4000);
    expect($child->contract['principal_penalty_bp'])->toBe(100);
    expect($child->contract['withholding_bp'])->toBe(1500);
    expect($child->contract['days'])->toBe(60);
    expect($child->funded_at->equalTo(app(Clock::class)->now()))->toBeTrue();
    expect($child->accrual_starts_at->equalTo(app(Clock::class)->now()->setTimezone(config('app.timezone'))->startOfDay()->addDay()))->toBeTrue();
    expect($plan->fresh()->contract['annual_rate_bp'])->toBe(36500);
    expect(fn () => app(SavingsPlans::class)->draft($plan->user, $effective, new Money(10000), 60, (string) Str::uuid()))->toThrow(DomainException::class, 'no longer offered');
});

test('renewal elections propagate through successive children until disabled', function (): void {
    Queue::fake([DeliverSavingsNotice::class]);
    $parent = renewalSavingsPlan();
    app(SavingsPlans::class)->electRollover($parent->user, $parent, true, 30, 'all_net', true);
    app(Clock::class)->set($parent->matures_at);
    app(SavingsProcessing::class)->process($parent->id);
    $child = SavingsPlan::where('parent_plan_id', $parent->id)->sole();
    app(Clock::class)->set($child->matures_at);
    app(SavingsProcessing::class)->process($child->id);
    $grandchild = SavingsPlan::where('parent_plan_id', $child->id)->sole();
    expect($grandchild->contract['principal'])->toBe('16129');
    expect($grandchild->rollover_election['enabled'])->toBeTrue();
    app(SavingsPlans::class)->electRollover($grandchild->user, $grandchild, false, null, null, false);
    app(Clock::class)->set($grandchild->matures_at);
    app(SavingsProcessing::class)->process($grandchild->id);
    expect($grandchild->fresh()->closure_reason)->toBe('matured_withdrawal');
    $this->assertDatabaseCount('savings_plans', 3);
    $this->assertDatabaseCount('plan_settlements', 3);
    expect(app(Ledger::class)->projectionMismatches())->toBe([]);
});

test('business ineligibility falls back to wallet settlement under recovery priority', function (string $cause): void {
    Queue::fake([DeliverSavingsNotice::class]);
    $plan = renewalSavingsPlan();
    app(SavingsPlans::class)->electRollover($plan->user, $plan, true, 30, 'original_principal', true);
    app(Clock::class)->set($plan->matures_at->subHour());
    if ($cause === 'limits') {
        reviseRenewalProduct($plan, ['minimum' => '20000']);
    } elseif ($cause === 'duration') {
        reviseRenewalProduct($plan, ['terms' => [['days' => 60, 'annual_rate_bp' => 36500]]]);
    } elseif ($cause === 'unavailable') {
        reviseRenewalProduct($plan, ['available' => false]);
    } elseif ($cause === 'consent') {
        $plan->refresh()->forceFill(['rollover_election' => ['enabled' => true, 'days' => 30, 'amount_mode' => 'all_net']])->save();
    } elseif ($cause === 'inactive') {
        $plan->user->forceFill(['is_active' => false])->save();
    } else {
        app(Ledger::class)->post('renewal-recovery', [
            ['account_id' => LedgerAccount::where('code', 'customer:'.$plan->user_id.':reversal_receivable')->sole()->id, 'amount' => new Money(1000)],
            ['account_id' => LedgerAccount::where('code', 'house:cash_at_gateway')->sole()->id, 'amount' => new Money(-1000)],
        ], '2028-03-29', 'Recovery fixture', $plan->user_id);
    }
    app(Clock::class)->set($plan->matures_at);
    app(SavingsProcessing::class)->process($plan->id);
    expect($plan->fresh()->closure_reason)->toBe('matured_withdrawal');
    $this->assertDatabaseCount('savings_plans', 1);
    expect(DB::table('plan_settlements')->sole()->wallet_credit)->toBe($cause === 'recovery' ? 11700 : 12700);
    expect(app(Ledger::class)->projectionMismatches())->toBe([]);
})->with(['limits', 'duration', 'unavailable', 'consent', 'inactive', 'recovery']);

test('child creation failure rolls back parent settlement and retry creates exactly one funded child', function (): void {
    Queue::fake([DeliverSavingsNotice::class]);
    $plan = renewalSavingsPlan();
    app(SavingsPlans::class)->electRollover($plan->user, $plan, true, 30, 'all_net', true);
    app(Clock::class)->set($plan->matures_at);
    DB::unprepared("CREATE TRIGGER fail_renewal BEFORE INSERT ON savings_plans WHEN NEW.parent_plan_id IS NOT NULL BEGIN SELECT RAISE(ABORT, 'Interrupted renewal'); END");
    expect(fn () => app(SavingsProcessing::class)->process($plan->id))->toThrow(QueryException::class, 'Interrupted renewal');
    expect((string) $plan->fresh()->state)->toBe('active');
    $this->assertDatabaseCount('plan_settlements', 0);
    $this->assertDatabaseCount('savings_notices', 0);
    $this->assertDatabaseCount('savings_plans', 1);
    $this->assertDatabaseMissing('journals', ['operation_key' => 'plan-settlement:'.$plan->id]);
    DB::unprepared('DROP TRIGGER fail_renewal');
    app(SavingsProcessing::class)->process($plan->id);
    app(SavingsProcessing::class)->process($plan->id);
    $this->assertDatabaseCount('savings_plans', 2);
    $this->assertDatabaseCount('plan_settlements', 1);
    expect(app(Ledger::class)->projectionMismatches())->toBe([]);
});

test('customer rollover controls require consent and close at the maturity boundary', function (): void {
    Queue::fake([DeliverSavingsNotice::class]);
    $plan = renewalSavingsPlan();
    $this->actingAs($plan->user)->patch(route('savings.update', $plan), ['action' => 'rollover', 'enabled' => '1', 'days' => 30, 'amount_mode' => 'all_net'])
        ->assertSessionHasErrors('consent');
    $this->patch(route('savings.update', $plan), ['action' => 'rollover', 'enabled' => '1', 'days' => 30, 'amount_mode' => 'all_net', 'consent' => '1'])
        ->assertSessionHasNoErrors()->assertRedirect(route('savings.show', $plan));
    expect($plan->fresh()->rollover_election['enabled'])->toBeTrue();
    app(Clock::class)->set($plan->matures_at);
    $this->patch(route('savings.update', $plan), ['action' => 'rollover', 'enabled' => '0'])->assertSessionHasErrors('action');
    expect($plan->fresh()->rollover_election['enabled'])->toBeTrue();
});

test('rollover waits for bounded child entitlement preparation and retries atomically', function (): void {
    Queue::fake([DeliverSavingsNotice::class, PrepareSavingsCalculation::class]);
    $plan = renewalSavingsPlan();
    app(SavingsPlans::class)->electRollover($plan->user, $plan, true, 30, 'all_net', true);
    app(Clock::class)->set($plan->matures_at->subHour());
    reviseRenewalProduct($plan, ['interest' => 'daily']);
    config(['savings.preparation_steps' => 3]);
    app(Clock::class)->set($plan->matures_at);
    app(SavingsProcessing::class)->process($plan->id);
    expect((string) $plan->fresh()->state)->toBe('active');
    $this->assertDatabaseCount('plan_settlements', 0);
    $this->assertDatabaseCount('savings_plans', 1);
    $pending = DB::table('savings_calculations')->whereNull('completed_at')->sole();
    while (! app(SavingsAccrual::class)->prepareCalculation($pending->fingerprint)) {
    }
    app(SavingsProcessing::class)->process($plan->id);
    app(SavingsProcessing::class)->process($plan->id);
    expect($plan->fresh()->closure_reason)->toBe('rolled_over');
    expect(SavingsPlan::where('parent_plan_id', $plan->id)->sole()->contract['principal'])->toBe('12700');
    $this->assertDatabaseCount('plan_settlements', 1);
    expect(app(Ledger::class)->projectionMismatches())->toBe([]);
});
