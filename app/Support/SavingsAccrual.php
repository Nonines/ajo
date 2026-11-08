<?php

namespace App\Support;

use App\Contracts\Clock;
use App\Jobs\PrepareSavingsCalculation;
use App\Models\LedgerAccount;
use App\Models\SavingsPlan;
use App\Support\PlanStates\Active;
use App\Support\PlanStates\Suspended;
use Brick\Math\BigInteger;
use Brick\Math\BigRational;
use Brick\Math\Exception\IntegerOverflowException;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Closure;
use DomainException;
use Illuminate\Support\Facades\DB;

class SavingsAccrual
{
    public function __construct(private FinancialTransaction $transactions, private Ledger $ledger, private Clock $clock) {}

    public function dailyMicro(string $principal, int $rate): string
    {
        return (string) BigRational::of($principal)->multipliedBy($rate)->multipliedBy(1000000)
            ->dividedBy(3650000)->toScale(0, RoundingMode::HalfEven);
    }

    public function rounded(string $micro): int
    {
        return BigRational::of($micro)->dividedBy(1000000)->toScale(0, RoundingMode::HalfEven)->toBigInteger()->toInt();
    }

    /** @param array<string, mixed> $contract */
    public function validateEntitlement(array $contract): void
    {
        try {
            $this->entitlement($contract, $contract['days']);
        } catch (\Throwable $exception) {
            throw new DomainException('The full contractual entitlement exceeds supported integer kobo.', previous: $exception);
        }
    }

    /**
     * @param  array<string, mixed>  $contract
     * @return array{micro: string, recognized: int, capitalized: int}|null
     */
    public function entitlement(array $contract, int $days): ?array
    {
        if ($days === 0) {
            return ['micro' => '0', 'recognized' => 0, 'capitalized' => 0];
        }
        $inputs = $this->calculationContract($contract);
        $fingerprint = hash('sha256', json_encode([$inputs, $days], JSON_THROW_ON_ERROR));
        $this->transactions->run([], function () use ($inputs, $days, $fingerprint): void {
            if (! DB::table('savings_calculations')->where('fingerprint', $fingerprint)->exists()) {
                DB::table('savings_calculations')->insert(['fingerprint' => $fingerprint, 'contract' => json_encode($inputs, JSON_THROW_ON_ERROR),
                    'through_days' => $days, 'next_process_at' => $this->clock->now()]);
            }
        });
        $this->prepareCalculation($fingerprint);
        $calculation = DB::table('savings_calculations')->where('fingerprint', $fingerprint)->sole();
        if ($calculation->failure !== null) {
            throw new DomainException($calculation->failure);
        }
        if ($calculation->completed_at === null) {
            DB::afterCommit(function () use ($fingerprint): void {
                try {
                    PrepareSavingsCalculation::dispatch($fingerprint);
                } catch (\Throwable) {
                    // Discovery retries the durable preparation after a queue outage.
                }
            });

            return null;
        }
        $last = DB::table('savings_calculation_steps')->where('fingerprint', $fingerprint)->orderByDesc('completed_days')->firstOrFail();

        return ['micro' => $last->micro_kobo, 'recognized' => $last->recognized, 'capitalized' => $last->capitalized];
    }

    /** @param array<string, mixed> $contract */
    public function preparationStatus(array $contract): string
    {
        $fingerprint = hash('sha256', json_encode([$this->calculationContract($contract), $contract['days']], JSON_THROW_ON_ERROR));
        $row = DB::table('savings_calculations')->where('fingerprint', $fingerprint)->first();

        return $row?->failure !== null ? 'failed' : ($row?->completed_at !== null ? 'ready' : 'preparing');
    }

    public function prepareCalculation(string $fingerprint): bool
    {
        return $this->transactions->run([], function () use ($fingerprint): bool {
            $row = DB::table('savings_calculations')->where('fingerprint', $fingerprint)->sole();
            if ($row->completed_at !== null || $row->failure !== null) {
                return true;
            }
            $contract = json_decode($row->contract, true, flags: JSON_THROW_ON_ERROR);
            if (! hash_equals($fingerprint, hash('sha256', json_encode([$contract, $row->through_days], JSON_THROW_ON_ERROR)))) {
                throw new DomainException('Savings calculation inputs do not match their immutable fingerprint.');
            }
            $last = DB::table('savings_calculation_steps')->where('fingerprint', $fingerprint)->orderByDesc('completed_days')->first();
            $completed = $last->completed_days ?? 0;
            $micro = BigInteger::of($last->micro_kobo ?? '0');
            $capitalized = $last->capitalized ?? 0;
            $interval = $this->interval($contract);
            try {
                for ($step = 0; $step < config('savings.preparation_steps') && $completed < $row->through_days; $step++) {
                    $block = $contract['annual_rate_bp'] === 0 ? $row->through_days - $completed : min($interval ?? $row->through_days, $row->through_days - $completed);
                    $principal = BigInteger::of($contract['principal'])->plus($capitalized);
                    $micro = $micro->plus(BigInteger::of($this->dailyMicro((string) $principal, $contract['annual_rate_bp']))->multipliedBy($block));
                    $completed += $block;
                    $recognized = $this->rounded((string) $micro);
                    BigInteger::of($contract['principal'])->plus($recognized)->toInt();
                    if ($interval !== null && $completed % $interval === 0 && $completed < $contract['days']) {
                        $capitalized = $recognized;
                    }
                }
            } catch (IntegerOverflowException) {
                DB::table('savings_calculations')->where('fingerprint', $fingerprint)->update(['failure' => 'The full contractual entitlement exceeds supported integer kobo.', 'next_process_at' => null]);

                return true;
            }
            DB::table('savings_calculation_steps')->insert(['fingerprint' => $fingerprint, 'completed_days' => $completed,
                'micro_kobo' => (string) $micro, 'recognized' => $this->rounded((string) $micro), 'capitalized' => $capitalized, 'created_at' => $this->clock->now()]);
            $ready = $completed === $row->through_days;
            DB::table('savings_calculations')->where('fingerprint', $fingerprint)->update([
                'next_process_at' => $ready ? null : $this->clock->now(), 'completed_at' => $ready ? $this->clock->now() : null]);

            return $ready;
        });
    }

    /** @param array<string, mixed> $contract
     * @return array{principal: string, annual_rate_bp: int, days: int, interest: string}
     */
    private function calculationContract(array $contract): array
    {
        return ['principal' => $contract['principal'], 'annual_rate_bp' => $contract['annual_rate_bp'], 'days' => $contract['days'], 'interest' => $contract['interest'] ?? 'simple'];
    }

    /** @param array<string, mixed> $contract */
    public function interval(array $contract): ?int
    {
        return match ($contract['interest']) {
            'simple' => null, 'daily' => 1, '30_days' => 30,
            default => throw new DomainException('Unsupported contractual interest mode.'),
        };
    }

    public function date(SavingsPlan $plan, string $date): bool
    {
        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date, 'Africa/Lagos');
        if ($parsed === null || $parsed->format('Y-m-d') !== $date) {
            throw new DomainException('Use an exact YYYY-MM-DD accrual date.');
        }

        return $this->run($plan, function () use ($plan, $date, $parsed): bool {
            $current = SavingsPlan::findOrFail($plan->id);
            if ($current->accrual_starts_at === null || $current->matures_at === null || $parsed->lessThan($current->accrual_starts_at)
                || $parsed->greaterThanOrEqualTo($current->matures_at) || $parsed->addDay()->greaterThan($this->clock->now())) {
                throw new DomainException('Only completed contractual Lagos days can accrue.');
            }
            if (DB::table('savings_exceptions')->where('savings_plan_id', $current->id)->exists()) {
                return false;
            }
            $previous = DB::table('plan_accruals')->where('savings_plan_id', $current->id)->where('accrual_date', $parsed->subDay()->toDateString())->first();
            if (! $parsed->equalTo($current->accrual_starts_at) && $previous === null) {
                throw new DomainException('Backfill accrual dates in chronological order.');
            }
            $capitalized = (int) DB::table('plan_capitalizations')->where('savings_plan_id', $current->id)->where('boundary_date', '<=', $date)->sum('amount');
            $bearing = (string) BigInteger::of($current->contract['principal'])->plus($capitalized);
            $micro = $this->dailyMicro($bearing, $current->contract['annual_rate_bp']);
            $cumulative = (string) BigInteger::of($previous->cumulative_micro_kobo ?? '0')->plus($micro);
            $recognized = $this->rounded($cumulative);
            $inputs = ['contract' => $current->contract, 'funded_at' => $current->funded_at?->toIso8601String(),
                'start' => $current->accrual_starts_at->toIso8601String(), 'maturity' => $current->matures_at->toIso8601String(),
                'date' => $date, 'previous_micro' => $previous->cumulative_micro_kobo ?? '0', 'micro' => $micro, 'cumulative' => $cumulative, 'recognized' => $recognized];
            if ($current->contract['interest'] !== 'simple') {
                $inputs['interest_principal'] = $bearing;
                $inputs['capitalized_before'] = (string) $capitalized;
            }
            $fingerprint = hash('sha256', json_encode($inputs, JSON_THROW_ON_ERROR));
            $existing = DB::table('plan_accruals')->where('savings_plan_id', $current->id)->where('accrual_date', $date)->first();
            if ($existing !== null) {
                if (! hash_equals($existing->fingerprint, $fingerprint) || $existing->inputs !== json_encode($inputs, JSON_THROW_ON_ERROR)
                    || $existing->micro_kobo !== $micro || $existing->cumulative_micro_kobo !== $cumulative || $existing->recognized_kobo !== $recognized) {
                    $this->investigate($current, 'accrual:'.$date, 'Accrual replay inputs or recorded entitlement changed.');

                    return false;
                }

                return $this->capitalize($current, $parsed, $recognized, $capitalized, true);
            }
            if (! $current->state instanceof Active && ! $current->state instanceof Suspended) {
                throw new DomainException('This lifecycle state cannot accrue a new date.');
            }
            $delta = $recognized - ($previous->recognized_kobo ?? 0);
            $journal = null;
            if ($delta > 0) {
                $interest = LedgerAccount::where('code', 'customer:'.$current->user_id.':plan:'.$current->id.':interest')->sole();
                $journal = $this->ledger->post('plan-accrual:'.$current->id.':'.$date, [
                    ['account_id' => LedgerAccount::where('code', 'house:interest_expense')->sole()->id, 'amount' => new Money($delta)],
                    ['account_id' => $interest->id, 'amount' => new Money(-$delta)],
                ], $date, 'Savings interest accrual', $current->user_id);
            }
            DB::table('plan_accruals')->insert(['savings_plan_id' => $current->id, 'accrual_date' => $date,
                'inputs' => json_encode($inputs, JSON_THROW_ON_ERROR), 'fingerprint' => $fingerprint, 'micro_kobo' => $micro,
                'cumulative_micro_kobo' => $cumulative, 'recognized_kobo' => $recognized, 'journal_id' => $journal?->id, 'posted_at' => $this->clock->now()]);

            return $this->capitalize($current, $parsed, $recognized, $capitalized, false);
        });
    }

    private function capitalize(SavingsPlan $plan, CarbonImmutable $date, int $recognized, int $previousCapitalized, bool $replay): bool
    {
        $interval = $this->interval($plan->contract);
        $boundary = $date->addDay();
        $completed = (int) $plan->accrual_starts_at->diffInDays($boundary);
        if ($interval === null || $completed % $interval !== 0 || $boundary->greaterThanOrEqualTo($plan->matures_at)) {
            return true;
        }
        $amount = $recognized - $previousCapitalized;
        $existing = DB::table('plan_capitalizations')->where('savings_plan_id', $plan->id)->where('boundary_date', $boundary->toDateString())->first();
        if ($replay) {
            if ($existing === null || $existing->amount !== $amount || $existing->cumulative_capitalized !== $recognized) {
                $this->investigate($plan, 'capitalization:'.$boundary->toDateString(), 'Capitalization disagrees with its dated accrual evidence.');

                return false;
            }

            return true;
        }
        $journal = null;
        if ($amount > 0) {
            $prefix = 'customer:'.$plan->user_id.':plan:'.$plan->id.':';
            $journal = $this->ledger->post('plan-capitalization:'.$plan->id.':'.$boundary->toDateString(), [
                ['account_id' => LedgerAccount::where('code', $prefix.'interest')->sole()->id, 'amount' => new Money($amount)],
                ['account_id' => LedgerAccount::where('code', $prefix.'principal')->sole()->id, 'amount' => new Money(-$amount)],
            ], $boundary->toDateString(), 'Savings interest capitalization', $plan->user_id);
        }
        DB::table('plan_capitalizations')->insert(['savings_plan_id' => $plan->id, 'boundary_date' => $boundary->toDateString(),
            'amount' => $amount, 'cumulative_capitalized' => $recognized, 'journal_id' => $journal?->id, 'posted_at' => $this->clock->now()]);

        return true;
    }

    public function settle(SavingsPlan $plan): bool
    {
        return $this->run($plan, fn (): bool => app(SavingsSettlements::class)->mature($plan));
    }

    /** @return array{micro: string, recognized: int, capitalized: int}|null */
    public function evidence(SavingsPlan $plan, int $days): ?array
    {
        $query = DB::table('plan_accruals')->where('savings_plan_id', $plan->id);
        $coverage = (clone $query)->selectRaw('COUNT(*) AS total, MIN(accrual_date) AS first_date, MAX(accrual_date) AS last_date')->first();
        if ($coverage->total !== $days) {
            throw new DomainException('Complete every contractual accrual date through the settlement cutoff.');
        }
        $expected = $this->entitlement($plan->contract, $days);
        if ($expected === null) {
            throw new DomainException('Interest calculation is being prepared. Retry after processing finishes.');
        }
        $interval = $this->interval($plan->contract);
        $expectedBoundaries = $interval === null ? 0 : intdiv(min($days, $plan->contract['days'] - 1), $interval);
        $capitalizations = DB::table('plan_capitalizations')->where('savings_plan_id', $plan->id);
        if ((clone $capitalizations)->count() !== $expectedBoundaries || (int) $capitalizations->sum('amount') !== $expected['capitalized']) {
            $this->investigate($plan, 'capitalization-coverage', 'Capitalization history disagrees with the contractual schedule.');

            return null;
        }
        if ($days === 0) {
            return $expected;
        }
        $firstDate = $plan->accrual_starts_at->setTimezone('Africa/Lagos')->toDateString();
        $lastDate = $plan->accrual_starts_at->setTimezone('Africa/Lagos')->addDays($days - 1)->toDateString();
        if ($coverage->first_date !== $firstDate || $coverage->last_date !== $lastDate) {
            $this->investigate($plan, 'accrual-coverage', 'Recorded dates disagree with the contractual accrual range.');

            return null;
        }
        if (! $this->date($plan, $lastDate)) {
            return null;
        }
        $last = (clone $query)->where('accrual_date', $lastDate)->sole();
        if ($last->cumulative_micro_kobo !== $expected['micro'] || $last->recognized_kobo !== $expected['recognized']) {
            $this->investigate($plan, 'accrual-entitlement', 'Terminal accrual disagrees with the full contractual entitlement.');

            return null;
        }

        return $expected;
    }

    public function completedDays(SavingsPlan $plan): int
    {
        $cutoff = $this->clock->now()->setTimezone('Africa/Lagos')->startOfDay()->min($plan->matures_at);

        return max(0, (int) $plan->accrual_starts_at->diffInDays($cutoff, false));
    }

    public function catchUp(SavingsPlan $plan): bool
    {
        $days = $this->completedDays($plan);
        $last = DB::table('plan_accruals')->where('savings_plan_id', $plan->id)->max('accrual_date');
        $date = $last === null ? $plan->accrual_starts_at->setTimezone('Africa/Lagos') : CarbonImmutable::parse($last, 'Africa/Lagos')->addDay();
        $end = $plan->accrual_starts_at->addDays($days);
        for ($count = 0; $count < config('savings.processing_days') && $date->lessThan($end); $count++) {
            if (! $this->date($plan, $date->toDateString())) {
                throw new DomainException('Accrual evidence requires investigation before closure.');
            }
            $date = $date->addDay();
        }
        if ($date->lessThan($end)) {
            SavingsPlan::whereKey($plan->id)->whereIn('state', ['active', 'suspended', 'matured'])->update(['next_process_at' => $this->clock->now()]);

            return false;
        }

        return true;
    }

    /** @param Closure(): bool $operation */
    private function run(SavingsPlan $plan, Closure $operation): bool
    {
        try {
            return $this->transactions->run([$plan->user_id], $operation);
        } catch (IntegerOverflowException) {
            $this->transactions->run([$plan->user_id], function () use ($plan): void {
                $this->investigate($plan, 'posting-bounds', 'A savings posting exceeds supported ledger balances; the original liability is retained.');
            });

            return false;
        }
    }

    public function investigate(SavingsPlan $plan, string $operation, string $message): void
    {
        DB::table('savings_exceptions')->insertOrIgnore(['savings_plan_id' => $plan->id,
            'operation' => 'plan:'.$plan->id.':'.$operation, 'message' => $message, 'created_at' => $this->clock->now()]);
    }
}
