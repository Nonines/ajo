<?php

namespace App\Support;

use App\Contracts\Clock;
use App\Models\SavingsPlan;
use App\Models\SavingsProduct;
use App\Models\Staff;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Builds fictional history through the same payment, contract, accrual, and closure services as the app.
 */
class DemoHistory
{
    public function __construct(private DemoTimeMachine $machine, private Clock $clock, private SavingsPlans $plans) {}

    public function seed(): void
    {
        $this->machine->requireDemo();
        $state = $this->machine->state();
        if ($state['seeded_at'] !== null) {
            return;
        }
        $admin = Staff::role('admin')->where('is_active', true)->orderBy('id')->first();
        if ($admin === null) {
            throw new DomainException('Create an initial administrator before seeding demo history.');
        }
        app(PaymentSimulator::class)->authorize($admin);
        $password = config('demo.customer_password');
        if (! is_string($password) || strlen($password) < 12) {
            throw new DomainException('Set DEMO_CUSTOMER_PASSWORD to at least 12 characters before seeding.');
        }
        $anchor = CarbonImmutable::parse(DB::table('demo_state')->where('id', 1)->value('history_starts_at'), 'UTC');
        $this->stage(0, function () use ($admin, $password, $anchor): void {
            $this->machine->assertCompatible();
            if (! $this->clock->now()->equalTo($anchor) || $this->machine->state()['target_at'] !== null) {
                throw new DomainException('Seed history before advancing the initial demo clock. Existing data has been left unchanged.');
            }
            if (User::exists() || SavingsProduct::exists() || DB::table('journals')->exists()) {
                throw new DomainException('Demo history requires an empty customer, product, and financial dataset. Existing staff may remain.');
            }
            $user = new User;
            $user->forceFill(['name' => 'Demo Customer', 'email' => config('demo.customer_email'), 'password' => $password,
                'email_verified_at' => $this->clock->now(), 'created_at' => $this->clock->now()])->save();
            $simulation = app(PaymentSimulator::class)->create($admin, $user, 'deposit', new Money(20000000));
            app(PaymentSimulator::class)->observe($admin, $simulation, 'success');
            // Complete the signed receipt before spending, even while the fixture transaction is open.
            foreach (DB::table('webhook_events')->whereNull('processed_at')->orderBy('id')->pluck('id') as $id) {
                app(PaymentInbox::class)->process($id);
            }
            $simple = $this->product($admin, 'Demo Fixed Savings', 'simple', 'until_maturity');
            $compound = $this->product($admin, 'Demo Compound Savings', '30_days', 'until_maturity');
            $terminable = $this->product($admin, 'Demo Early Access Savings', 'simple', 'terminable');
            $this->fund($user, $simple, 5000000, 360, 'demo:history:simple');
            $this->fund($user, $compound, 3000000, 360, 'demo:history:compound');
            $rollover = $this->fund($user, $simple, 1000000, 90, 'demo:history:rollover');
            $this->plans->electRollover($user, $rollover, true, 90, 'original_principal', true);
            $this->fund($user, $terminable, 1000000, 90, 'demo:history:early');
        });
        if ($this->machine->state()['history_step'] === 1) {
            $this->advanceTo($admin, $anchor->addDays(45));
            $this->stage(1, static function (): void {});
        }
        $this->stage(2, function (): void {
            $plan = SavingsPlan::where('request_key', 'demo:history:early')->sole();
            $quote = app(SavingsSettlements::class)->preview($plan->user, $plan);
            app(SavingsSettlements::class)->terminate($plan->user, $plan, $quote['token']);
        });
        if ($this->machine->state()['history_step'] === 3) {
            $this->advanceTo($admin, $anchor->addDays(385));
            $this->stage(3, static function (): void {});
        }
        $this->stage(4, function (): void {
            $user = User::where('email', config('demo.customer_email'))->sole();
            $simple = SavingsProduct::where('name', 'Demo Fixed Savings')->sole();
            $this->fund($user, $simple, 1000000, 30, 'demo:history:near-maturity');
            $this->fund($user, $simple, 2000000, 90, 'demo:history:active');
        });
        if ($this->machine->state()['history_step'] === 5) {
            $this->advanceTo($admin, $anchor->addDays(config('demo.history_days')));
            $this->stage(5, function () use ($admin): void {
                if (app(Ledger::class)->projectionMismatches() !== []) {
                    throw new DomainException('Demo history ledger projections do not reconcile.');
                }
                app(AuditTrail::class)->record('demo.history_seeded', properties: ['days' => config('demo.history_days')], actor: $admin);
                app(AuditTrail::class)->verify();
                DB::table('demo_state')->where('id', 1)->update(['seeded_at' => $this->clock->now()]);
            });
        }
    }

    private function stage(int $step, \Closure $operation): void
    {
        $this->machine->exclusive(function () use ($step, $operation): void {
            DB::transaction(function () use ($step, $operation): void {
                if ((int) DB::table('demo_state')->where('id', 1)->value('history_step') !== $step) {
                    return;
                }
                $operation();
                DB::table('demo_state')->where('id', 1)->update(['history_step' => $step + 1]);
            });
        });
    }

    private function advanceTo(Staff $admin, CarbonImmutable $target): void
    {
        $state = $this->machine->state();
        $now = $this->clock->now();
        if ($now->greaterThan($target)) {
            throw new DomainException('Demo time passed an unfinished history stage. History cannot rewind.');
        }
        if ($state['target_at'] !== null && ! CarbonImmutable::parse($state['target_at'], 'UTC')->equalTo($target)) {
            throw new DomainException('Finish the existing demo advance before resuming history.');
        }
        if ($state['target_at'] === null && $now->lessThan($target)) {
            $this->machine->start($admin, (int) $now->diffInDays($target), $now->toIso8601String(), history: true);
        }
        if ($state['failure'] !== null) {
            $this->machine->resume($admin);
        }
        while ($this->machine->state()['target_at'] !== null) {
            $this->machine->process();
        }
    }

    private function product(Staff $admin, string $name, string $interest, string $lock): SavingsProduct
    {
        return app(SavingsProducts::class)->publish($admin, [
            'name' => $name, 'disclosure' => 'Fictional demo product and illustrative terms. Simulated money only.',
            'minimum' => '100000', 'maximum' => '100000000', 'withholding_bp' => 1000,
            'terms' => [['days' => 30, 'annual_rate_bp' => 1000], ['days' => 90, 'annual_rate_bp' => 1000], ['days' => 360, 'annual_rate_bp' => 1000]],
            'interest' => $interest, 'lock' => $lock, 'minimum_holding_days' => $lock === 'terminable' ? 7 : null,
            'forfeiture_bp' => $lock === 'terminable' ? 5000 : 0, 'principal_penalty_bp' => $lock === 'terminable' ? 100 : 0,
        ]);
    }

    private function fund(User $user, SavingsProduct $product, int $principal, int $days, string $key): SavingsPlan
    {
        $version = $product->versions()->where('version', $product->current_version)->sole();
        $plan = $this->plans->draft($user, $version, new Money($principal), $days, $key);
        $this->plans->accept($user, $plan);
        // Entitlement preparation is bounded; complete its real batches before attempting funding again.
        for ($attempt = 0; $attempt < config('demo.maximum_passes'); $attempt++) {
            $plan = $this->plans->fund($user, $plan);
            if ((string) $plan->state === 'active' && $plan->funded_at !== null && $plan->funding_journal_id !== null) {
                return $plan;
            }
        }
        throw new DomainException('Demo plan entitlement preparation did not complete.');
    }
}
