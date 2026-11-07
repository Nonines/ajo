<?php

namespace App\Support;

use App\Contracts\Clock;
use App\Models\LedgerAccount;
use App\Models\SavingsPlan;
use App\Models\SavingsProduct;
use App\Models\SavingsProductVersion;
use App\Models\User;
use App\Support\PlanStates\Active;
use App\Support\PlanStates\Cancelled;
use App\Support\PlanStates\Draft;
use App\Support\PlanStates\PendingFunding;
use Brick\Math\BigInteger;
use Brick\Math\Exception\IntegerOverflowException;
use Carbon\CarbonImmutable;
use DomainException;

class SavingsPlans
{
    public function __construct(private FinancialTransaction $transactions, private Clock $clock, private Ledger $ledger, private SavingsAccrual $accrual) {}

    public function draft(User $user, SavingsProductVersion $version, Money $principal, int $days, string $requestKey): SavingsPlan
    {
        return $this->transactions->run([$user->id], function () use ($user, $version, $principal, $days, $requestKey): SavingsPlan {
            $this->eligible($user);
            $existing = SavingsPlan::where('request_key', $requestKey)->first();
            if ($existing !== null) {
                if ($existing->user_id !== $user->id || $existing->savings_product_version_id !== $version->id
                    || $existing->contract['principal'] !== (string) $principal->kobo || $existing->contract['days'] !== $days) {
                    throw new DomainException('Request key was already used for another contract.');
                }

                return $existing;
            }
            $version->refresh();
            if (SavingsProduct::findOrFail($version->savings_product_id)->current_version !== $version->version) {
                throw new DomainException('This product version is no longer offered. Choose the current terms.');
            }
            $contract = $version->contract;
            $term = collect($contract['terms'])->firstWhere('days', $days);
            if ($term === null || $principal->kobo < (new Money($contract['minimum']))->kobo || $principal->kobo > (new Money($contract['maximum']))->kobo) {
                throw new DomainException('Choose an offered term and an amount within the product limits.');
            }
            $contract += ['principal' => (string) $principal->kobo, 'days' => $days, 'annual_rate_bp' => $term['annual_rate_bp']];
            $this->accrual->validateEntitlement($contract);
            $hours = config('savings.funding_hours');
            if (! is_int($hours) || $hours < 1) {
                throw new DomainException('Funding deadline configuration must be positive whole hours.');
            }
            $plan = new SavingsPlan;
            $plan->forceFill(['user_id' => $user->id, 'savings_product_version_id' => $version->id,
                'request_key' => $requestKey, 'contract' => $contract, 'state' => Draft::class,
                'created_at' => $this->clock->now(), 'funding_deadline' => $this->clock->now()->addHours($hours)])->save();

            return $plan;
        });
    }

    public function accept(User $user, SavingsPlan $plan): SavingsPlan
    {
        return $this->transactions->run([$user->id], function () use ($user, $plan): SavingsPlan {
            $current = $this->owned($user, $plan);
            $this->eligible($user);
            if ($this->expire($current)) {
                return $current;
            }
            if ($current->state instanceof PendingFunding) {
                return $current;
            }
            if (! $current->state instanceof Draft) {
                throw new DomainException('Only drafts can accept terms.');
            }
            $current->state->transitionTo(PendingFunding::class);

            return $current;
        });
    }

    public function fund(User $user, SavingsPlan $plan): SavingsPlan
    {
        return $this->transactions->run([$user->id], function () use ($user, $plan): SavingsPlan {
            $current = $this->owned($user, $plan);
            $this->eligible($user);
            if ($current->funding_journal_id !== null) {
                return $current;
            }
            if ($this->expire($current)) {
                return $current;
            }
            if (! $current->state instanceof PendingFunding) {
                throw new DomainException('Accept the contract before funding.');
            }
            $receivable = LedgerAccount::where('code', 'customer:'.$user->id.':reversal_receivable')->sole();
            if ($this->ledger->balance($receivable)->kobo > 0) {
                throw new DomainException('Repay the recovery balance before funding a new plan.');
            }
            $this->accrual->validateEntitlement($current->contract);
            $gross = $this->accrual->rounded((string) BigInteger::of($this->accrual->dailyMicro($current->contract['principal'], $current->contract['annual_rate_bp']))->multipliedBy($current->contract['days']));
            $tax = (new BasisPoints($current->contract['withholding_bp']))->apply(new Money($gross));
            try {
                BigInteger::of($this->ledger->balance(LedgerAccount::where('code', 'house:interest_expense')->sole())->kobo)->plus($gross)->toInt();
                BigInteger::of($this->ledger->balance(LedgerAccount::where('code', 'house:withholding_tax_payable')->sole())->kobo)->minus($tax->kobo)->toInt();
            } catch (IntegerOverflowException $exception) {
                throw new DomainException('The contractual postings exceed supported account balances.', previous: $exception);
            }
            $start = $this->clock->now()->setTimezone('Africa/Lagos')->startOfDay()->addDay();
            if ($current->contract['days'] > $start->diffInDays(CarbonImmutable::parse('9999-12-31', 'Africa/Lagos'))) {
                throw new DomainException('The term exceeds supported calendar dates.');
            }
            $maturity = $start->addDays($current->contract['days']);
            foreach (['principal', 'interest'] as $suffix) {
                (new LedgerAccount)->forceFill(['code' => 'customer:'.$user->id.':plan:'.$current->id.':'.$suffix,
                    'type' => 'liability', 'user_id' => $user->id])->save();
            }
            $principal = new Money($current->contract['principal']);
            $journal = $this->ledger->post('plan-funding:'.$current->id, [
                ['account_id' => LedgerAccount::where('code', 'customer:'.$user->id.':wallet')->sole()->id, 'amount' => $principal],
                ['account_id' => $this->account($current, 'principal')->id, 'amount' => $principal->negate()],
            ], $this->clock->now()->setTimezone('Africa/Lagos')->toDateString(), 'Savings plan funding', $user->id);
            $current->forceFill(['funded_at' => $this->clock->now(), 'accrual_starts_at' => $start->utc(), 'matures_at' => $maturity->utc(),
                'funding_journal_id' => $journal->id, 'next_process_at' => $start->addDay()->addMinutes(5)->utc()])->save();
            $current->state->transitionTo(Active::class);

            return $current;
        });
    }

    public function cancel(User $user, SavingsPlan $plan): SavingsPlan
    {
        return $this->transactions->run([$user->id], function () use ($user, $plan): SavingsPlan {
            $current = $this->owned($user, $plan);
            $this->eligible($user);
            if ($current->state instanceof Cancelled) {
                return $current;
            }
            if (! $current->state instanceof Draft && ! $current->state instanceof PendingFunding) {
                throw new DomainException('Funded contracts remain locked until maturity.');
            }
            $current->forceFill(['closure_reason' => 'customer_cancelled', 'closed_at' => $this->clock->now()])->save();
            $current->state->transitionTo(Cancelled::class);

            return $current;
        });
    }

    public function expire(SavingsPlan $plan): bool
    {
        if (($plan->state instanceof Draft || $plan->state instanceof PendingFunding) && $this->clock->now()->greaterThanOrEqualTo($plan->funding_deadline)) {
            $plan->forceFill(['closure_reason' => 'funding_expired', 'closed_at' => $this->clock->now()])->save();
            $plan->state->transitionTo(Cancelled::class);

            return true;
        }

        return $plan->state instanceof Cancelled;
    }

    public function account(SavingsPlan $plan, string $suffix): LedgerAccount
    {
        return LedgerAccount::where('code', 'customer:'.$plan->user_id.':plan:'.$plan->id.':'.$suffix)->sole();
    }

    private function owned(User $user, SavingsPlan $plan): SavingsPlan
    {
        return SavingsPlan::where('user_id', $user->id)->findOrFail($plan->id);
    }

    private function eligible(User $user): void
    {
        $current = User::findOrFail($user->id);
        if (! $current->is_active || ! $current->hasVerifiedEmail()) {
            throw new DomainException('Only active verified customers can manage savings.');
        }
    }
}
