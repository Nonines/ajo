<?php

use App\Contracts\Clock;
use App\Filament\Ops\Resources\ApprovalRequests\Pages\ManageApprovalRequests;
use App\Filament\Ops\Resources\SavingsPlans\Pages\ManageSavingsPlans;
use App\Jobs\DeliverSavingsNotice;
use App\Models\ApprovalRequest;
use App\Models\LedgerAccount;
use App\Models\SavingsNotice;
use App\Models\SavingsPlan;
use App\Models\User;
use App\Support\Approvals;
use App\Support\FrozenClock;
use App\Support\Ledger;
use App\Support\Money;
use App\Support\PlanStates\Suspended;
use App\Support\SavingsAccrual;
use App\Support\SavingsEvidenceMismatch;
use App\Support\SavingsPlans;
use App\Support\SavingsSettlements;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Livewire\Livewire;

beforeEach(function (): void {
    app()->instance(Clock::class, new FrozenClock(CarbonImmutable::parse('2028-02-27T23:00:00Z')));
    config(['inertia.ssr.enabled' => false]);
});

function terminableSavingsPlan(): SavingsPlan
{
    return fundedAccrualPlan(1000000, 36500, ['interest' => '30_days', 'days' => 60, 'lock' => 'terminable',
        'minimum_holding_days' => 7, 'forfeiture_bp' => 5000, 'principal_penalty_bp' => 200]);
}

function completeClosureHistory(SavingsPlan $plan): void
{
    while (! app(SavingsAccrual::class)->catchUp($plan)) {
    }
}

test('early closure forfeits both capitalized and unpaid interest and taxes only retained interest', function (): void {
    Queue::fake([DeliverSavingsNotice::class]);
    $plan = terminableSavingsPlan();
    config(['savings.processing_days' => 2]);
    app(Clock::class)->set($plan->accrual_starts_at->addDays(35)->addHours(12));
    $service = app(SavingsSettlements::class);
    expect(fn () => $service->preview($plan->user, $plan))->toThrow(DomainException::class, 'catching up');
    $this->assertDatabaseCount('plan_accruals', 2);
    completeClosureHistory($plan);
    $preview = $service->preview($plan->user, $plan);
    expect($preview['gross_interest'])->toBe('365000');
    expect($preview['capitalized_interest'])->toBe('300000');
    expect($preview['forfeiture'])->toBe('182500');
    expect($preview['forfeited_payable'])->toBe('65000');
    expect($preview['forfeited_capitalized'])->toBe('117500');
    expect($preview['withholding'])->toBe('18250');
    expect($preview['principal_penalty'])->toBe('20000');
    expect($preview['wallet_credit'])->toBe('1144250');
    $this->assertDatabaseCount('plan_accruals', 35);

    $service->terminate($plan->user, $plan, $preview['token']);
    $service->terminate($plan->user, $plan, $preview['token']);

    $statement = DB::table('plan_settlements')->sole();
    expect($statement->closure_reason)->toBe('early_termination');
    expect($statement->forfeiture_journal_id)->not->toBeNull();
    expect($statement->penalty_journal_id)->not->toBeNull();
    expect(LedgerAccount::where('code', 'house:interest_expense')->sole()->balance->kobo)->toBe(182500);
    expect(LedgerAccount::where('code', 'house:penalty_income')->sole()->balance->kobo)->toBe(-20000);
    expect(app(Ledger::class)->projectionMismatches())->toBe([]);
    $this->assertDatabaseCount('savings_notices', 1);
    expect(SavingsNotice::sole()->data['type'])->toBe('savings_early_termination');
    $this->actingAs($plan->user)->get(route('savings.show', $plan))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('statement.forfeited_capitalized', '117500')->where('statement.withholding', '18250'));
    Queue::assertPushed(DeliverSavingsNotice::class, 1);
});

test('minimum holding is measured from accrual start at the exact Lagos boundary', function (): void {
    $plan = terminableSavingsPlan();
    app(Clock::class)->set($plan->accrual_starts_at->addDays(7)->subSecond());
    expect(fn () => app(SavingsSettlements::class)->preview($plan->user, $plan))->toThrow(DomainException::class, 'minimum holding');
    app(Clock::class)->set($plan->accrual_starts_at->addDays(7));
    expect(app(SavingsSettlements::class)->preview($plan->user, $plan)['gross_interest'])->toBe('70000');
    $this->assertDatabaseCount('plan_accruals', 7);
});

test('legacy and uncancellable locks cannot exit or receive a penalty waiver', function (string $lock): void {
    $plan = fundedAccrualPlan(changes: ['lock' => $lock]);
    app(Clock::class)->set($plan->accrual_starts_at->addDays(10));
    expect(fn () => app(SavingsSettlements::class)->preview($plan->user, $plan))->toThrow(DomainException::class, 'cannot be cancelled');
    expect(fn () => app(Approvals::class)->propose(payoutStaff(), 'plan.waive', $plan, ['interest_cap' => '100', 'principal_cap' => '100'], 'Waiver'))
        ->toThrow(DomainException::class, 'cannot be cancelled');
    $this->assertDatabaseCount('plan_settlements', 0);
})->with(['until_maturity', 'uncancellable']);

test('confirmation rejects a stale preview after midnight suspension or recovery', function (string $change): void {
    $plan = terminableSavingsPlan();
    app(Clock::class)->set($plan->accrual_starts_at->addDays(10));
    $preview = app(SavingsSettlements::class)->preview($plan->user, $plan);
    if ($change === 'midnight') {
        app(Clock::class)->set($plan->accrual_starts_at->addDays(11));
    } elseif ($change === 'suspension') {
        $plan->state->transitionTo(Suspended::class);
    } elseif ($change === 'maturity') {
        app(Clock::class)->set($plan->matures_at);
    } else {
        app(Ledger::class)->post('recovery-after-preview', [
            ['account_id' => LedgerAccount::where('code', 'customer:'.$plan->user_id.':reversal_receivable')->sole()->id, 'amount' => new Money(1)],
            ['account_id' => LedgerAccount::where('code', 'house:cash_at_gateway')->sole()->id, 'amount' => new Money(-1)],
        ], '2028-03-10', 'Recovery fixture', $plan->user_id);
    }
    expect(fn () => app(SavingsSettlements::class)->terminate($plan->user, $plan, $preview['token']))->toThrow(DomainException::class);
    $this->assertDatabaseCount('plan_settlements', 0);
    expect(app(Ledger::class)->projectionMismatches())->toBe([]);
})->with(['midnight', 'suspension', 'recovery', 'maturity']);

test('distinct staff approve separate single use waiver caps and settlement recomputes within each cap', function (): void {
    Queue::fake([DeliverSavingsNotice::class]);
    $plan = terminableSavingsPlan();
    app(Clock::class)->set($plan->accrual_starts_at->addDays(35));
    completeClosureHistory($plan);
    $maker = payoutStaff();
    $approval = app(Approvals::class)->propose($maker, 'plan.waive', $plan, ['interest_cap' => '10000', 'principal_cap' => '5000'], 'Explicit caps');
    expect(fn () => app(Approvals::class)->decide($maker, $approval, true, 'Self approve'))->toThrow(AuthorizationException::class);
    app(Approvals::class)->decide(payoutStaff(), $approval, true, 'Independent review');
    $waiver = DB::table('plan_waivers')->sole();
    $preview = app(SavingsSettlements::class)->preview($plan->user, $plan, $waiver->id);
    expect($preview['waived_forfeiture'])->toBe('10000');
    expect($preview['waived_penalty'])->toBe('5000');
    expect($preview['forfeiture'])->toBe('172500');
    expect($preview['withholding'])->toBe('19250');
    expect($preview['wallet_credit'])->toBe('1158250');

    app(SavingsSettlements::class)->terminate($plan->user, $plan, $preview['token'], $waiver->id);
    app(SavingsSettlements::class)->terminate($plan->user, $plan, $preview['token'], $waiver->id);
    expect(DB::table('plan_waivers')->sole()->used_at)->not->toBeNull();
    expect(DB::table('plan_settlements')->sole()->waiver_id)->toBe($waiver->id);
    $this->assertDatabaseCount('plan_settlements', 1);
    $this->assertDatabaseCount('savings_notices', 2);
    expect(app(Ledger::class)->projectionMismatches())->toBe([]);
});

test('waiver validity starts at approval and expiry is enforced at use and notified once', function (): void {
    Queue::fake([DeliverSavingsNotice::class]);
    $plan = terminableSavingsPlan();
    app(Clock::class)->set($plan->accrual_starts_at->addDays(10));
    $approval = app(Approvals::class)->propose(payoutStaff(), 'plan.waive', $plan, ['interest_cap' => '999999', 'principal_cap' => '999999'], 'Full waiver ceiling');
    app(Clock::class)->set(app(Clock::class)->now()->addHours(23));
    app(Approvals::class)->decide(payoutStaff(), $approval, true, 'Approve later');
    $waiver = DB::table('plan_waivers')->sole();
    expect(CarbonImmutable::parse($waiver->expires_at)->equalTo(app(Clock::class)->now()->addHours(24)))->toBeTrue();
    $preview = app(SavingsSettlements::class)->preview($plan->user, $plan, $waiver->id);
    expect($preview['forfeiture'])->toBe('0');
    expect($preview['principal_penalty'])->toBe('0');
    app(Clock::class)->set(CarbonImmutable::parse($waiver->expires_at));
    completeClosureHistory($plan);
    expect(fn () => app(SavingsSettlements::class)->terminate($plan->user, $plan, $preview['token'], $waiver->id))->toThrow(DomainException::class, 'expired');
    app(SavingsSettlements::class)->expireWaivers();
    app(SavingsSettlements::class)->expireWaivers();
    expect(DB::table('plan_waivers')->sole()->expired_at)->not->toBeNull();
    expect(SavingsNotice::get()->filter(fn (SavingsNotice $notice) => $notice->data['type'] === 'savings_waiver_expired'))->toHaveCount(1);
    $this->assertDatabaseCount('plan_settlements', 0);
});

test('waivers cannot be transferred to another plan or used after staff permission loss', function (string $change): void {
    $plan = terminableSavingsPlan();
    app(Clock::class)->set($plan->accrual_starts_at->addDays(10));
    $maker = payoutStaff();
    $approval = app(Approvals::class)->propose($maker, 'plan.waive', $plan, ['interest_cap' => '100', 'principal_cap' => '100'], 'Capped waiver');
    app(Approvals::class)->decide(payoutStaff(), $approval, true, 'Independent');
    $waiver = DB::table('plan_waivers')->sole();
    if ($change === 'other-plan') {
        $plan = terminableSavingsPlan();
        app(Clock::class)->set($plan->accrual_starts_at->addDays(10));
        $expected = DomainException::class;
    } else {
        $maker->forceFill(['is_active' => false])->save();
        $expected = AuthorizationException::class;
    }
    expect(fn () => app(SavingsSettlements::class)->preview($plan->user, $plan, $waiver->id))->toThrow($expected);
    $this->assertDatabaseCount('plan_settlements', 0);
})->with(['other-plan', 'permission-loss']);

test('a failed terminal write rolls back waiver use forfeiture penalty and settlement', function (): void {
    Queue::fake([DeliverSavingsNotice::class]);
    $plan = terminableSavingsPlan();
    app(Clock::class)->set($plan->accrual_starts_at->addDays(35));
    completeClosureHistory($plan);
    $approval = app(Approvals::class)->propose(payoutStaff(), 'plan.waive', $plan, ['interest_cap' => '100', 'principal_cap' => '100'], 'Caps');
    app(Approvals::class)->decide(payoutStaff(), $approval, true, 'Checked');
    $waiver = DB::table('plan_waivers')->sole();
    $preview = app(SavingsSettlements::class)->preview($plan->user, $plan, $waiver->id);
    DB::unprepared("CREATE TRIGGER fail_closure BEFORE INSERT ON plan_settlements BEGIN SELECT RAISE(ABORT, 'Interrupted closure'); END");
    expect(fn () => app(SavingsSettlements::class)->terminate($plan->user, $plan, $preview['token'], $waiver->id))->toThrow(QueryException::class, 'Interrupted closure');
    expect(DB::table('plan_waivers')->sole()->used_at)->toBeNull();
    $this->assertDatabaseMissing('journals', ['operation_key' => 'plan-forfeiture:'.$plan->id]);
    $this->assertDatabaseMissing('journals', ['operation_key' => 'plan-penalty:'.$plan->id]);
    expect((string) $plan->fresh()->state)->toBe('active');
    DB::unprepared('DROP TRIGGER fail_closure');
    app(SavingsSettlements::class)->terminate($plan->user, $plan, $preview['token'], $waiver->id);
    $this->assertDatabaseCount('plan_settlements', 1);
    expect(app(Ledger::class)->projectionMismatches())->toBe([]);
});

test('administrative closure requires two admins and corrects erroneous funding before accrual starts', function (): void {
    Queue::fake([DeliverSavingsNotice::class]);
    $plan = fundedAccrualPlan(changes: ['lock' => 'uncancellable']);
    $payload = ['correction' => 'erroneous_funding', 'evidence' => 'Verified duplicate funding instruction'];
    expect(fn () => app(Approvals::class)->propose(payoutStaff(), 'plan.close', $plan, $payload, 'Correct error'))->toThrow(AuthorizationException::class);
    expect(fn () => app(Approvals::class)->propose(payoutStaff('admin'), 'plan.close', $plan, ['correction' => 'other', 'evidence' => 'Collect recovery debt'], 'Accelerate recovery'))
        ->toThrow(DomainException::class, 'erroneous creation or funding');
    $maker = payoutStaff('admin');
    $approval = app(Approvals::class)->propose($maker, 'plan.close', $plan, $payload, 'Correct verified error');
    expect(fn () => app(Approvals::class)->decide($maker, $approval, true, 'Self'))->toThrow(AuthorizationException::class);
    app(Approvals::class)->decide(payoutStaff('admin'), $approval, true, 'Evidence checked');
    $statement = DB::table('plan_settlements')->sole();
    expect($statement->gross_interest)->toBe(0);
    expect($statement->wallet_credit)->toBe(1000000);
    expect($statement->accrual_through)->toBeNull();
    expect($statement->closure_reason)->toBe('admin_closed');
    expect($statement->approval_request_id)->toBe($approval->id);
    $this->assertDatabaseCount('plan_accruals', 0);
    expect(app(Ledger::class)->projectionMismatches())->toBe([]);
});

test('administrative closure returns compounded earned interest without early exit deductions', function (): void {
    Queue::fake([DeliverSavingsNotice::class]);
    $plan = terminableSavingsPlan();
    app(Clock::class)->set($plan->accrual_starts_at->addDays(35)->addHours(12));
    completeClosureHistory($plan);
    $approval = app(Approvals::class)->propose(payoutStaff('admin'), 'plan.close', $plan,
        ['correction' => 'other', 'evidence' => 'Verified administrative correction'], 'Correct contract');
    app(Approvals::class)->decide(payoutStaff('admin'), $approval, true, 'Independently checked');
    $statement = DB::table('plan_settlements')->sole();
    expect($statement->gross_interest)->toBe(365000);
    expect($statement->forfeiture)->toBe(0);
    expect($statement->principal_penalty)->toBe(0);
    expect($statement->withholding)->toBe(36500);
    expect($statement->wallet_credit)->toBe(1328500);
});

test('a fully consumed principal and interest can close with no artificial zero transfer', function (): void {
    Queue::fake([DeliverSavingsNotice::class]);
    $plan = fundedAccrualPlan(10000, 36500, ['lock' => 'terminable', 'minimum_holding_days' => 1, 'forfeiture_bp' => 10000, 'principal_penalty_bp' => 10000]);
    app(Clock::class)->set($plan->accrual_starts_at->addDays(10));
    $quote = app(SavingsSettlements::class)->preview($plan->user, $plan);
    app(SavingsSettlements::class)->terminate($plan->user, $plan, $quote['token']);
    expect(DB::table('plan_settlements')->sole()->wallet_credit)->toBe(0);
    expect((string) $plan->fresh()->state)->toBe('closed');
    expect(DB::table('ledger_entries')->where('amount', 0)->exists())->toBeFalse();
    expect(app(Ledger::class)->projectionMismatches())->toBe([]);
});

test('customer preview and confirmation enforce authentication ownership validation and exact money transport', function (): void {
    Queue::fake([DeliverSavingsNotice::class]);
    $plan = terminableSavingsPlan();
    app(Clock::class)->set($plan->accrual_starts_at->addDays(35));
    completeClosureHistory($plan);
    $this->postJson(route('savings.preview', $plan))->assertUnauthorized();
    $this->actingAs(User::factory()->create())->postJson(route('savings.preview', $plan))->assertNotFound();
    $this->patch(route('savings.update', $plan), ['action' => 'terminate', 'token' => str_repeat('0', 64)])->assertNotFound();
    $quote = $this->actingAs($plan->user)->postJson(route('savings.preview', $plan))->assertOk()->assertJsonPath('wallet_credit', '1144250')->json();
    $this->patch(route('savings.update', $plan), ['action' => 'terminate', 'token' => 'invalid'])->assertSessionHasErrors('token');
    $this->patch(route('savings.update', $plan), ['action' => 'terminate', 'token' => $quote['token']])->assertRedirect(route('savings.show', $plan));
    expect($plan->fresh()->closure_reason)->toBe('early_termination');
});

test('staff plan actions issue a waiver through a separate checker action', function (): void {
    Queue::fake([DeliverSavingsNotice::class]);
    $plan = terminableSavingsPlan();
    app(Clock::class)->set($plan->accrual_starts_at->addDays(10));
    $this->actingAs(payoutStaff(), 'staff');
    Filament::setCurrentPanel('ops');
    Livewire::test(ManageSavingsPlans::class)->callAction(TestAction::make('waive')->table($plan),
        data: ['interest_cap' => '1000', 'principal_cap' => '2000', 'reason' => 'Capped waiver'])->assertHasNoFormErrors();
    $approval = ApprovalRequest::sole();
    $this->actingAs(payoutStaff(), 'staff');
    Livewire::test(ManageApprovalRequests::class)->callAction(TestAction::make('approve')->table($approval), data: ['reason' => 'Checked caps'])->assertHasNoFormErrors();
    expect(DB::table('plan_waivers')->sole()->principal_cap)->toBe(2000);
});

test('waiver rejection and pending expiry notify the customer without issuing a usable waiver', function (string $outcome): void {
    Queue::fake([DeliverSavingsNotice::class]);
    $plan = terminableSavingsPlan();
    app(Clock::class)->set($plan->accrual_starts_at->addDays(10));
    $approval = app(Approvals::class)->propose(payoutStaff(), 'plan.waive', $plan, ['interest_cap' => '100', 'principal_cap' => '100'], 'Proposed waiver');
    if ($outcome === 'expired') {
        app(Clock::class)->set($approval->expires_at);
        $this->artisan('withdrawals:recover')->assertSuccessful();
    } else {
        app(Approvals::class)->decide(payoutStaff(), $approval, false, 'No supporting evidence');
    }
    expect($approval->fresh()->state)->toBe($outcome);
    expect(SavingsNotice::sole()->data['type'])->toBe('savings_waiver_'.$outcome);
    $this->assertDatabaseCount('plan_waivers', 0);
    $this->assertDatabaseCount('plan_settlements', 0);
})->with(['rejected', 'expired']);

test('configurable waiver lifetime starts after approval and immutable caps survive direct update attempts', function (): void {
    $plan = terminableSavingsPlan();
    config(['savings.waiver_hours' => 2]);
    app(Clock::class)->set($plan->accrual_starts_at->addDays(10));
    $approval = app(Approvals::class)->propose(payoutStaff(), 'plan.waive', $plan, ['interest_cap' => '100', 'principal_cap' => '100'], 'Caps');
    app(Approvals::class)->decide(payoutStaff(), $approval, true, 'Checked');
    $waiver = DB::table('plan_waivers')->sole();
    expect(CarbonImmutable::parse($waiver->expires_at)->equalTo(app(Clock::class)->now()->addHours(2)))->toBeTrue();
    expect(fn () => DB::table('plan_waivers')->where('id', $waiver->id)->update(['interest_cap' => 200]))->toThrow(QueryException::class, 'immutable');
});

test('a successful admin closure remains protected by the retained settlement triggers', function (): void {
    Queue::fake([DeliverSavingsNotice::class]);
    $plan = fundedAccrualPlan();
    $approval = app(Approvals::class)->propose(payoutStaff('admin'), 'plan.close', $plan,
        ['correction' => 'erroneous_creation', 'evidence' => 'Verified erroneous instruction'], 'Correct creation');
    app(Approvals::class)->decide(payoutStaff('admin'), $approval, true, 'Verified');
    expect(fn () => DB::table('plan_settlements')->where('savings_plan_id', $plan->id)->update(['withholding' => 1]))->toThrow(QueryException::class, 'immutable');
});

test('waiver ceilings reject unsupported integer bounds without creating a proposal', function (): void {
    $plan = terminableSavingsPlan();
    app(Clock::class)->set($plan->accrual_starts_at->addDays(10));
    expect(fn () => app(Approvals::class)->propose(payoutStaff(), 'plan.waive', $plan,
        ['interest_cap' => (string) PHP_INT_MAX, 'principal_cap' => '1'], 'Excessive caps'))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('approval_requests', 0);
});

test('rejected customer and staff closures retain an investigation after rolling back settlement', function (string $entry): void {
    Queue::fake([DeliverSavingsNotice::class]);
    $plan = terminableSavingsPlan();
    app(Clock::class)->set($plan->accrual_starts_at->addDays(10));
    $quote = $entry === 'confirmation' ? app(SavingsSettlements::class)->preview($plan->user, $plan) : null;
    $approval = $entry === 'admin' ? app(Approvals::class)->propose(payoutStaff('admin'), 'plan.close', $plan,
        ['correction' => 'other', 'evidence' => 'Verified correction'], 'Review closure') : null;
    app(Ledger::class)->post('liability-discrepancy', [
        ['account_id' => app(SavingsPlans::class)->account($plan, 'principal')->id, 'amount' => new Money(1)],
        ['account_id' => LedgerAccount::where('code', 'house:cash_at_gateway')->sole()->id, 'amount' => new Money(-1)],
    ], '2028-03-10', 'Discrepancy fixture', $plan->user_id);
    expect(fn () => match ($entry) {
        'preview' => app(SavingsSettlements::class)->preview($plan->user, $plan),
        'confirmation' => app(SavingsSettlements::class)->terminate($plan->user, $plan, $quote['token']),
        'admin' => app(Approvals::class)->decide(payoutStaff('admin'), $approval, true, 'Check closure'),
    })->toThrow(SavingsEvidenceMismatch::class);
    $this->assertDatabaseCount('savings_exceptions', 1);
    $this->assertDatabaseCount('plan_settlements', 0);
    expect((string) $plan->fresh()->state)->toBe('active');
    if ($approval !== null) {
        expect($approval->fresh()->state)->toBe('pending');
        expect($approval->fresh()->applied_at)->toBeNull();
    }
    expect(fn () => app(SavingsSettlements::class)->preview($plan->user, $plan))->toThrow(DomainException::class, 'investigation');
})->with(['preview', 'confirmation', 'admin']);
