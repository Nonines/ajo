<?php

namespace App\Support;

use App\Contracts\Clock;
use App\Models\LedgerAccount;
use App\Models\SavingsPlan;
use App\Support\PlanStates\Active;
use App\Support\PlanStates\Closed;
use App\Support\PlanStates\Matured;
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
    public function __construct(private FinancialTransaction $transactions, private Ledger $ledger, private Clock $clock, private SavingsNotices $notices) {}

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
            $gross = $this->rounded((string) BigInteger::of($this->dailyMicro($contract['principal'], $contract['annual_rate_bp']))->multipliedBy($contract['days']));
            BigInteger::of($contract['principal'])->plus($gross)->toInt();
        } catch (\Throwable $exception) {
            throw new DomainException('The full contractual entitlement exceeds supported integer kobo.', previous: $exception);
        }
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
            $micro = $this->dailyMicro($current->contract['principal'], $current->contract['annual_rate_bp']);
            $cumulative = (string) BigInteger::of($previous->cumulative_micro_kobo ?? '0')->plus($micro);
            $recognized = $this->rounded($cumulative);
            $inputs = ['contract' => $current->contract, 'funded_at' => $current->funded_at?->toIso8601String(),
                'start' => $current->accrual_starts_at->toIso8601String(), 'maturity' => $current->matures_at->toIso8601String(),
                'date' => $date, 'previous_micro' => $previous->cumulative_micro_kobo ?? '0', 'micro' => $micro, 'cumulative' => $cumulative, 'recognized' => $recognized];
            $fingerprint = hash('sha256', json_encode($inputs, JSON_THROW_ON_ERROR));
            $existing = DB::table('plan_accruals')->where('savings_plan_id', $current->id)->where('accrual_date', $date)->first();
            if ($existing !== null) {
                if (! hash_equals($existing->fingerprint, $fingerprint) || $existing->inputs !== json_encode($inputs, JSON_THROW_ON_ERROR)
                    || $existing->micro_kobo !== $micro || $existing->cumulative_micro_kobo !== $cumulative || $existing->recognized_kobo !== $recognized) {
                    $this->investigate($current, 'accrual:'.$date, 'Accrual replay inputs or recorded entitlement changed.');

                    return false;
                }

                return true;
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

            return true;
        });
    }

    public function settle(SavingsPlan $plan): bool
    {
        return $this->run($plan, function () use ($plan): bool {
            $current = SavingsPlan::findOrFail($plan->id);
            if (DB::table('savings_exceptions')->where('savings_plan_id', $current->id)->exists()) {
                return false;
            }
            if ($current->state instanceof Closed) {
                return true;
            }
            if ($current->state instanceof Suspended) {
                return false;
            }
            if ((! $current->state instanceof Active && ! $current->state instanceof Matured) || $current->matures_at === null || $this->clock->now()->lessThan($current->matures_at)) {
                throw new DomainException('The contract has not reached maturity.');
            }
            $query = DB::table('plan_accruals')->where('savings_plan_id', $current->id);
            $coverage = (clone $query)->selectRaw('COUNT(*) AS total, MIN(accrual_date) AS first_date, MAX(accrual_date) AS last_date')->first();
            if ($coverage->total !== $current->contract['days']) {
                throw new DomainException('Complete every contractual accrual date before settlement.');
            }
            $firstDate = $current->accrual_starts_at->setTimezone('Africa/Lagos')->toDateString();
            $lastDate = $current->matures_at->setTimezone('Africa/Lagos')->subDay()->toDateString();
            if ($coverage->first_date !== $firstDate || $coverage->last_date !== $lastDate) {
                $this->investigate($current, 'accrual-coverage', 'Recorded dates disagree with the contractual accrual range.');

                return false;
            }
            // Immutable, unique daily evidence makes terminal validation independent of term length.
            if (! $this->date($current, $lastDate)) {
                return false;
            }
            $last = (clone $query)->where('accrual_date', $lastDate)->sole();
            $expectedMicro = (string) BigInteger::of($this->dailyMicro($current->contract['principal'], $current->contract['annual_rate_bp']))->multipliedBy($current->contract['days']);
            if ($last->cumulative_micro_kobo !== $expectedMicro || $last->recognized_kobo !== $this->rounded($expectedMicro)) {
                $this->investigate($current, 'accrual-entitlement', 'Terminal accrual disagrees with the full contractual entitlement.');

                return false;
            }
            $principal = new Money($current->contract['principal']);
            $gross = new Money($last->recognized_kobo);
            $tax = (new BasisPoints($current->contract['withholding_bp']))->apply($gross);
            $net = BigInteger::of($principal->kobo)->plus($gross->kobo)->minus($tax->kobo)->toInt();
            $prefix = 'customer:'.$current->user_id.':';
            $principalAccount = LedgerAccount::where('code', $prefix.'plan:'.$current->id.':principal')->sole();
            $interestAccount = LedgerAccount::where('code', $prefix.'plan:'.$current->id.':interest')->sole();
            if ($this->ledger->balance($principalAccount)->kobo !== -$principal->kobo || $this->ledger->balance($interestAccount)->kobo !== -$gross->kobo) {
                $this->investigate($current, 'settlement', 'Plan liabilities disagree with its principal and accrued entitlement.');

                return false;
            }
            $debt = LedgerAccount::where('code', $prefix.'reversal_receivable')->sole();
            $debtBalance = $this->ledger->balance($debt)->kobo;
            $repaid = min($net, $debtBalance);
            $walletCredit = $net - $repaid;
            $entries = [['account_id' => $principalAccount->id, 'amount' => $principal]];
            foreach ([[$interestAccount->id, $gross->kobo], [LedgerAccount::where('code', 'house:withholding_tax_payable')->sole()->id, -$tax->kobo],
                [$debt->id, -$repaid], [LedgerAccount::where('code', $prefix.'wallet')->sole()->id, -$walletCredit]] as [$accountId, $amount]) {
                if ($amount !== 0) {
                    $entries[] = ['account_id' => $accountId, 'amount' => new Money($amount)];
                }
            }
            $journal = $this->ledger->post('plan-settlement:'.$current->id, $entries, $current->matures_at->setTimezone('Africa/Lagos')->toDateString(), 'Savings maturity settlement', $current->user_id);
            $statement = ['savings_plan_id' => $current->id, 'journal_id' => $journal->id, 'principal' => $principal->kobo,
                'gross_interest' => $gross->kobo, 'net_interest' => $gross->kobo - $tax->kobo, 'withholding' => $tax->kobo, 'remaining_debt' => $debtBalance - $repaid, 'debt_repayment' => $repaid, 'wallet_credit' => $walletCredit,
                'rounding_micro_kobo' => (string) BigInteger::of($gross->kobo)->multipliedBy(1000000)->minus($last->cumulative_micro_kobo), 'posted_at' => $this->clock->now()];
            DB::table('plan_settlements')->insert($statement);
            if ($current->state instanceof Active) {
                $current->state->transitionTo(Matured::class);
            }
            $current->forceFill(['closure_reason' => 'matured_withdrawal', 'closed_at' => $this->clock->now(), 'next_process_at' => null])->save();
            $current->state->transitionTo(Closed::class);
            // The notice intent must commit with the settlement, even if Redis is unavailable.
            $this->notices->record($current, $statement);

            return true;
        });
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
