<?php

namespace App\Support;

use App\Contracts\Clock;
use App\Jobs\DeliverSavingsNotice;
use App\Jobs\ProcessSavingsPlan;
use App\Models\SavingsNotice;
use App\Models\SavingsPlan;
use App\Support\PlanStates\Active;
use App\Support\PlanStates\Matured;
use App\Support\PlanStates\Suspended;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

class SavingsProcessing
{
    public function __construct(private Clock $clock, private SavingsPlans $plans, private SavingsAccrual $accrual, private FinancialTransaction $transactions) {}

    public function discover(): void
    {
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
        SavingsNotice::whereNull('mail_sent_at')->where('next_attempt_at', '<=', $this->clock->now())->eachById(function (SavingsNotice $notice): void {
            try {
                DeliverSavingsNotice::dispatch($notice->id);
            } catch (Throwable $exception) {
                report($exception);
            }
        }, 100);
    }

    public function process(int $id, ?string $through = null): void
    {
        $plan = SavingsPlan::findOrFail($id);
        if (! $plan->state instanceof Active && ! $plan->state instanceof Suspended && ! $plan->state instanceof Matured) {
            return;
        }
        if (DB::table('savings_exceptions')->where('savings_plan_id', $id)->exists()) {
            return;
        }
        $today = $this->clock->now()->setTimezone('Africa/Lagos')->startOfDay();
        $end = $plan->matures_at->setTimezone('Africa/Lagos')->subDay();
        $lastEligible = $today->subDay()->min($end);
        if ($through !== null) {
            $requested = CarbonImmutable::createFromFormat('!Y-m-d', $through, 'Africa/Lagos');
            if ($requested === null || $requested->format('Y-m-d') !== $through || $requested->greaterThan($lastEligible) || $requested->lessThan($plan->accrual_starts_at)) {
                throw new \DomainException('Backfills require a completed contractual date.');
            }
            $lastEligible = $requested;
        }
        $last = DB::table('plan_accruals')->where('savings_plan_id', $id)->max('accrual_date');
        $date = $last === null ? $plan->accrual_starts_at->setTimezone('Africa/Lagos') : CarbonImmutable::parse($last, 'Africa/Lagos')->addDay();
        for ($count = 0; $count < config('savings.processing_days') && $date->lessThanOrEqualTo($lastEligible); $count++, $date = $date->addDay()) {
            if (! $this->accrual->date($plan, $date->toDateString())) {
                return;
            }
        }
        if ($date->lessThanOrEqualTo($lastEligible)) {
            SavingsPlan::whereKey($id)->whereIn('state', ['active', 'suspended', 'matured'])->update(['next_process_at' => $this->clock->now()]);

            return;
        }
        if ($date->greaterThan($end) && $this->clock->now()->greaterThanOrEqualTo($plan->matures_at) && ! $plan->state instanceof Suspended) {
            $this->accrual->settle($plan);
        }
        SavingsPlan::whereKey($id)->whereIn('state', ['active', 'suspended', 'matured'])->update(['next_process_at' => $today->addDay()->addMinutes(5)->utc()]);
    }
}
