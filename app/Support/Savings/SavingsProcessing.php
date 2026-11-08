<?php

namespace App\Support\Savings;

use App\Contracts\Clock;
use App\Jobs\DeliverSavingsNotice;
use App\Jobs\PrepareSavingsCalculation;
use App\Jobs\ProcessSavingsPlan;
use App\Models\SavingsNotice;
use App\Models\SavingsPlan;
use App\Support\Ledger\FinancialTransaction;
use App\Support\Savings\PlanStates\Active;
use App\Support\Savings\PlanStates\Matured;
use App\Support\Savings\PlanStates\Suspended;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Discovers recoverable savings work and processes completed contractual days in bounded batches.
 */
class SavingsProcessing
{
    public function __construct(private Clock $clock, private SavingsPlans $plans, private SavingsAccrual $accrual, private FinancialTransaction $transactions) {}

    /**
     * Expires funding windows and waivers, then queues due calculations, plans, and notices.
     */
    public function discover(): void
    {
        app(SavingsSettlements::class)->expireWaivers();
        DB::table('savings_calculations')->whereNull('completed_at')->whereNull('failure')->where('next_process_at', '<=', $this->clock->now())
            ->orderBy('fingerprint')->each(function ($calculation): void {
                try {
                    PrepareSavingsCalculation::dispatch($calculation->fingerprint);
                } catch (Throwable $exception) {
                    report($exception);
                }
            });

        // Expire unfunded contracts before discovering funded lifecycle work.
        SavingsPlan::whereIn('state', ['draft', 'pending_funding'])->where('funding_deadline', '<=', $this->clock->now())->eachById(function (SavingsPlan $plan): void {
            try {
                $this->transactions->run([$plan->user_id], fn () => $this->plans->expire($plan->refresh()));
            } catch (Throwable $exception) {
                report($exception);
            }
        });
        SavingsPlan::whereIn('state', ['active', 'suspended', 'matured'])->whereNotIn('id', DB::table('savings_exceptions')->select('savings_plan_id'))
            ->where('next_process_at', '<=', $this->clock->now())->eachById(function (SavingsPlan $plan): void {
                try {
                    ProcessSavingsPlan::dispatch($plan->id);
                } catch (Throwable $exception) {
                    report($exception);
                }
            }, 100);

        // Recover notice delivery independently of whether its financial work already finished.
        SavingsNotice::whereNull('mail_sent_at')->where('next_attempt_at', '<=', $this->clock->now())->eachById(function (SavingsNotice $notice): void {
            try {
                DeliverSavingsNotice::dispatch($notice->id);
            } catch (Throwable $exception) {
                report($exception);
            }
        }, 100);
    }

    /**
     * Backfills eligible dates and attempts maturity settlement, retaining a due time for unfinished work.
     */
    public function process(int $id, ?string $through = null): void
    {
        $plan = SavingsPlan::findOrFail($id);

        if (! $plan->state instanceof Active && ! $plan->state instanceof Suspended && ! $plan->state instanceof Matured) {
            return;
        }

        if (DB::table('savings_exceptions')->where('savings_plan_id', $id)->exists()) {
            return;
        }

        // Accrue only completed application-calendar days within the contractual term.
        $today = $this->clock->now()->setTimezone(config('app.timezone'))->startOfDay();
        $end = $plan->matures_at->setTimezone(config('app.timezone'))->subDay();
        $lastEligible = $today->subDay()->min($end);

        if ($through !== null) {
            $requested = CarbonImmutable::createFromFormat('!Y-m-d', $through, config('app.timezone'));

            if ($requested === null || $requested->format('Y-m-d') !== $through || $requested->greaterThan($lastEligible) || $requested->lessThan($plan->accrual_starts_at)) {
                throw new \DomainException('Backfills require a completed contractual date.');
            }

            $lastEligible = $requested;
        }

        $last = DB::table('plan_accruals')->where('savings_plan_id', $id)->max('accrual_date');
        $date = $last === null ? $plan->accrual_starts_at->setTimezone(config('app.timezone')) : CarbonImmutable::parse($last, config('app.timezone'))->addDay();

        // Bound backfills per pass and leave unfinished plans immediately due.
        for ($count = 0; $count < config('savings.processing_days') && $date->lessThanOrEqualTo($lastEligible); $count++, $date = $date->addDay()) {
            if (! $this->accrual->date($plan, $date->toDateString())) {
                return;
            }
        }

        if ($date->lessThanOrEqualTo($lastEligible)) {
            SavingsPlan::whereKey($id)->whereIn('state', ['active', 'suspended', 'matured'])->update(['next_process_at' => $this->clock->now()]);

            return;
        }

        // Attempt settlement only after all contractual dates are covered and maturity has arrived.
        if ($date->greaterThan($end) && $this->clock->now()->greaterThanOrEqualTo($plan->matures_at) && ! $plan->state instanceof Suspended) {
            if (! $this->accrual->settle($plan)) {
                SavingsPlan::whereKey($id)->whereIn('state', ['active', 'matured'])->update(['next_process_at' => $this->clock->now()->addMinutes(config('savings.retry_minutes'))]);

                return;
            }
        }

        SavingsPlan::whereKey($id)->whereIn('state', ['active', 'suspended', 'matured'])->update(['next_process_at' => $today->addDay()->addMinutes(5)->utc()]);
    }
}
