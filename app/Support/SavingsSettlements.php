<?php

namespace App\Support;

use App\Contracts\Clock;
use App\Models\ApprovalRequest;
use App\Models\LedgerAccount;
use App\Models\SavingsPlan;
use App\Models\Staff;
use App\Models\User;
use App\Support\PlanStates\Active;
use App\Support\PlanStates\Closed;
use App\Support\PlanStates\Matured;
use App\Support\PlanStates\Suspended;
use Brick\Math\BigInteger;
use Brick\Math\Exception\IntegerOverflowException;
use Closure;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Reconciles savings liabilities before applying closure, recovery allocation, tax, and waivers.
 */
class SavingsSettlements
{
    public function __construct(private FinancialTransaction $transactions, private Clock $clock, private Ledger $ledger,
        private SavingsAccrual $accrual, private SavingsPlans $plans, private SavingsNotices $notices) {}

    /**
     * Requires an active terminable plan past its holding period, without recovery or investigation blocks.
     */
    public function earlyEligible(SavingsPlan $plan): void
    {
        if (! $plan->state instanceof Active || $plan->matures_at === null || $this->clock->now()->greaterThanOrEqualTo($plan->matures_at)) {
            throw new DomainException('Early termination requires an active plan before maturity.');
        }
        if ($plan->contract['lock'] !== 'terminable') {
            throw new DomainException('This contract cannot be cancelled before maturity.');
        }
        $minimum = $plan->contract['minimum_holding_days'] ?? null;
        if (! is_int($minimum) || $minimum < 1 || $minimum >= $plan->contract['days']
            || $this->clock->now()->lessThan($plan->accrual_starts_at->setTimezone(config('app.timezone'))->addDays($minimum))) {
            throw new DomainException('The contractual minimum holding period has not completed.');
        }
        if (app(RecoveryControls::class)->restricted($plan->user_id)) {
            throw new DomainException('Recovery restrictions block early termination.');
        }
        $this->unblocked($plan);
    }

    /**
     * Catches up accruals before producing a customer-owned closure quote and confirmation token.
     *
     * @return array<string, string>
     */
    public function preview(User $user, SavingsPlan $plan, ?int $waiverId = null): array
    {
        $current = $this->owned($user, $plan);
        $this->earlyEligible($current);
        $this->prepareClosure($current);

        return $this->customerOperation($user->id, function () use ($user, $plan, $waiverId): array {
            $current = $this->owned($user, $plan);
            $this->earlyEligible($current);
            $quote = $this->quote($current, true, $waiverId);
            $quote['token'] = $this->token($current, $quote);

            return $quote;
        });
    }

    /**
     * Rechecks the quote token under the customer lock before applying early termination.
     */
    public function terminate(User $user, SavingsPlan $plan, string $token, ?int $waiverId = null): SavingsPlan
    {
        return $this->customerOperation($user->id, function () use ($user, $plan, $token, $waiverId): SavingsPlan {
            $current = $this->owned($user, $plan);
            if ($current->state instanceof Closed && $current->closure_reason === 'early_termination') {
                return $current;
            }
            $this->earlyEligible($current);
            $quote = $this->quote($current, true, $waiverId);
            if (! hash_equals($this->token($current, $quote), $token)) {
                throw new DomainException('The settlement preview changed. Review a fresh preview before confirming.');
            }
            $this->close($current, $quote, 'early_termination');

            return $current->refresh();
        });
    }

    /**
     * Settles and optionally renews a mature plan atomically; false means blocked or unfinished work.
     */
    public function mature(SavingsPlan $plan): bool
    {
        return $this->transactions->run([$plan->user_id], function () use ($plan): bool {
            $current = SavingsPlan::findOrFail($plan->id);
            if ($current->state instanceof Closed) {
                return true;
            }
            if ($current->state instanceof Suspended || DB::table('savings_exceptions')->where('savings_plan_id', $current->id)->exists()) {
                return false;
            }
            if ((! $current->state instanceof Active && ! $current->state instanceof Matured) || $current->matures_at === null
                || $this->clock->now()->lessThan($current->matures_at)) {
                throw new DomainException('The contract has not reached maturity.');
            }
            $evidence = $this->accrual->evidence($current, $current->contract['days']);
            if ($evidence === null) {
                return false;
            }
            if (! $this->liabilitiesMatch($current, $evidence)) {
                return false;
            }
            $quote = $this->quote($current, false, evidence: $evidence);
            $candidate = $this->plans->renewalCandidate($current, (int) $quote['wallet_credit']);
            if ($candidate !== null && ! $candidate['ready']) {
                return false;
            }
            $renew = $candidate !== null;
            $this->close($current, $quote, $renew ? 'rolled_over' : 'matured_withdrawal');
            if ($renew) {
                $this->plans->renew($current);
            }

            return true;
        });
    }

    /**
     * Checks correction evidence and limits pre-maturity administrative closure of locked plans.
     *
     * @param  array<string, mixed>  $payload
     */
    public function validateAdministrative(SavingsPlan $plan, array $payload): void
    {
        if (! $plan->state instanceof Active && ! $plan->state instanceof Matured) {
            throw new DomainException('Administrative closure requires an unsuspended funded open plan.');
        }
        $values = Validator::make($payload, ['correction' => ['required', 'in:erroneous_creation,erroneous_funding,other'],
            'evidence' => ['required', 'string', 'max:10000']])->validate();
        if ($plan->contract['lock'] !== 'terminable' && $this->clock->now()->lessThan($plan->matures_at)
            && ! in_array($values['correction'], ['erroneous_creation', 'erroneous_funding'], true)) {
            throw new DomainException('Pre-maturity closure of a locked plan is only for erroneous creation or funding, never accelerated recovery.');
        }
        $this->unblocked($plan);
    }

    /**
     * Applies an unused two-staff closure approval within the approval transaction.
     */
    public function administrative(SavingsPlan $plan, ApprovalRequest $approval): void
    {
        $plan = SavingsPlan::findOrFail($plan->id);
        $approval = $this->approved($plan, $approval, 'plan.close', 'plans.close', 'plans.close');
        $this->validateAdministrative($plan, $approval->payload);
        if ($approval->state !== 'approved' || $approval->action !== 'plan.close' || $approval->subject_type !== SavingsPlan::class
            || $approval->subject_id !== $plan->id || $approval->requested_by === $approval->decided_by || $approval->applied_at !== null) {
            throw new DomainException('Administrative closure requires the current two-staff approval.');
        }
        $this->close($plan, $this->quote($plan, false), 'admin_closed', $approval->id);
    }

    /**
     * Validates positive combined waiver ceilings that fit supported integer kobo.
     *
     * @param  array<string, mixed>  $payload
     * @return array{interest_cap: string, principal_cap: string}
     */
    public function waiverTerms(SavingsPlan $plan, array $payload): array
    {
        $this->earlyEligible($plan);
        $values = Validator::make($payload, ['interest_cap' => ['required', 'regex:/^(0|[1-9][0-9]*)$/D'],
            'principal_cap' => ['required', 'regex:/^(0|[1-9][0-9]*)$/D']])->validate();
        try {
            $interest = new Money($values['interest_cap']);
            $principal = new Money($values['principal_cap']);
            $total = BigInteger::of($interest->kobo)->plus($principal->kobo)->toInt();
        } catch (IntegerOverflowException) {
            throw ValidationException::withMessages(['interest_cap' => 'The combined waiver ceiling must fit integer kobo.']);
        }
        if ($total <= 0) {
            throw new DomainException('A waiver requires a positive monetary ceiling.');
        }

        return ['interest_cap' => (string) $interest->kobo, 'principal_cap' => (string) $principal->kobo];
    }

    /**
     * Creates a time-limited waiver from current two-staff approval evidence.
     */
    public function issueWaiver(SavingsPlan $plan, ApprovalRequest $approval): int
    {
        $plan = SavingsPlan::findOrFail($plan->id);
        $approval = $this->approved($plan, $approval, 'plan.waive', 'waivers.make', 'waivers.check');
        $terms = $this->waiverTerms($plan, $approval->payload);
        $hours = config('savings.waiver_hours');
        if (! is_int($hours) || $hours < 1) {
            throw new DomainException('Waiver validity must be positive whole hours.');
        }
        $id = DB::table('plan_waivers')->insertGetId(['savings_plan_id' => $plan->id, 'approval_request_id' => $approval->id,
            ...$terms, 'approved_at' => $this->clock->now(), 'expires_at' => $this->clock->now()->addHours($hours)]);
        $this->notices->event($plan, 'savings_waiver_approved', ['waiver_id' => (string) $id, ...$terms,
            'expires_at' => $this->clock->now()->addHours($hours)->toIso8601String()], 'waiver:'.$id.':approved');

        return $id;
    }

    /**
     * Requires an active approval transaction and rechecks distinct staff, permissions, subject, and expiry.
     */
    private function approved(SavingsPlan $plan, ApprovalRequest $approval, string $action, string $makerPermission, string $checkerPermission): ApprovalRequest
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Approved savings changes require the approval transaction.');
        }
        $current = ApprovalRequest::findOrFail($approval->id);
        if ($current->state !== 'approved' || $current->action !== $action || $current->subject_type !== SavingsPlan::class
            || $current->subject_id !== $plan->id || $current->applied_at !== null || $current->decided_by === null
            || $current->requested_by === $current->decided_by || $current->expires_at->lessThanOrEqualTo($this->clock->now())) {
            throw new DomainException('Savings changes require current two-staff approval evidence.');
        }
        app(Approvals::class)->authorize(Staff::findOrFail($current->requested_by), $makerPermission);
        app(Approvals::class)->authorize(Staff::findOrFail($current->decided_by), $checkerPermission);

        return $current;
    }

    /**
     * Marks unused waivers expired and records their notification and audit evidence.
     */
    public function expireWaivers(): void
    {
        DB::table('plan_waivers')->whereNull('used_at')->whereNull('expired_at')->where('expires_at', '<=', $this->clock->now())
            ->orderBy('id')->eachById(function ($waiver): void {
                $plan = SavingsPlan::findOrFail((int) $waiver->savings_plan_id);
                $this->transactions->run([$plan->user_id], function () use ($waiver, $plan): void {
                    $current = DB::table('plan_waivers')->where('id', $waiver->id)->sole();
                    if ($current->used_at !== null || $current->expired_at !== null) {
                        return;
                    }
                    DB::table('plan_waivers')->where('id', $current->id)->update(['expired_at' => $this->clock->now()]);
                    $this->notices->event($plan, 'savings_waiver_expired', ['waiver_id' => (string) $current->id], 'waiver:'.$current->id.':expired');
                    app(AuditTrail::class)->record('plan.waiver_expired', $plan, ['waiver_id' => $current->id]);
                });
            });
    }

    /**
     * Reconciles accrued liabilities and computes deductions, waiver limits, tax, and debt-first proceeds.
     *
     * @param  array{micro: string, recognized: int, capitalized: int}|null  $evidence
     * @return array<string, string>
     */
    private function quote(SavingsPlan $plan, bool $early, ?int $waiverId = null, ?array $evidence = null): array
    {
        $days = $this->accrual->completedDays($plan);
        $evidence ??= $this->accrual->evidence($plan, $days);
        if ($evidence === null || ! $this->liabilitiesMatch($plan, $evidence)) {
            $failure = DB::table('savings_exceptions')->where('savings_plan_id', $plan->id)->orderByDesc('id')->firstOrFail();
            throw new SavingsEvidenceMismatch($plan->user_id, $plan->id, $failure->operation, $failure->message);
        }
        $gross = $evidence['recognized'];
        $principal = (new Money($plan->contract['principal']))->kobo;
        $forfeiture = $early ? (new BasisPoints($plan->contract['forfeiture_bp'] ?? 0))->apply(new Money($gross))->kobo : 0;
        $penalty = $early ? (new BasisPoints($plan->contract['principal_penalty_bp'] ?? 0))->apply(new Money($principal))->kobo : 0;
        $waivedInterest = $waivedPrincipal = 0;
        if ($waiverId !== null) {
            $waiver = DB::table('plan_waivers')->where('id', $waiverId)->where('savings_plan_id', $plan->id)->first();
            if ($waiver === null || $waiver->used_at !== null || $waiver->expired_at !== null
                || $this->clock->now()->greaterThanOrEqualTo($waiver->expires_at)) {
                throw new DomainException('This waiver is expired, used, or belongs to another plan.');
            }
            $approval = ApprovalRequest::findOrFail((int) $waiver->approval_request_id);
            if ($approval->state !== 'approved' || $approval->action !== 'plan.waive' || $approval->subject_type !== SavingsPlan::class
                || $approval->subject_id !== $plan->id || $approval->evidence['waiver_id'] !== $waiver->id
                || $approval->requested_by === $approval->decided_by) {
                throw new DomainException('Waiver approval evidence does not match this plan.');
            }
            app(Approvals::class)->authorize(Staff::findOrFail($approval->requested_by), 'waivers.make');
            app(Approvals::class)->authorize(Staff::findOrFail($approval->decided_by), 'waivers.check');
            $waivedInterest = min($forfeiture, $waiver->interest_cap);
            $waivedPrincipal = min($penalty, $waiver->principal_cap);
        }
        $forfeiture -= $waivedInterest;
        $penalty -= $waivedPrincipal;
        $retained = $gross - $forfeiture;
        $tax = (new BasisPoints($plan->contract['withholding_bp']))->apply(new Money($retained))->kobo;
        $net = BigInteger::of($principal)->minus($penalty)->plus($retained)->minus($tax)->toInt();
        $debt = app(RecoveryControls::class)->balance($plan->user_id);
        $repaid = min($net, $debt);
        $unpaid = $gross - $evidence['capitalized'];
        $quote = ['principal' => $principal, 'remaining_principal' => $principal - $penalty, 'gross_interest' => $gross, 'capitalized_interest' => $evidence['capitalized'],
            'forfeiture' => $forfeiture, 'forfeited_payable' => min($unpaid, $forfeiture),
            'forfeited_capitalized' => max(0, $forfeiture - $unpaid), 'principal_penalty' => $penalty,
            'waived_forfeiture' => $waivedInterest, 'waived_penalty' => $waivedPrincipal, 'withholding' => $tax,
            'net_interest' => $retained - $tax, 'incurred_fees' => 0, 'debt_repayment' => $repaid, 'remaining_debt' => $debt - $repaid,
            'wallet_credit' => $net - $repaid, 'rounding_micro_kobo' => (string) BigInteger::of($gross)->multipliedBy(1000000)->minus($evidence['micro']),
            'accrual_through' => $days === 0 ? '' : $plan->accrual_starts_at->setTimezone(config('app.timezone'))->addDays($days - 1)->toDateString(),
            'waiver_id' => $waiverId ?? '', 'quote_date' => $this->clock->now()->setTimezone(config('app.timezone'))->toDateString()];

        return array_map(strval(...), $quote);
    }

    /**
     * Checks signed principal and interest balances against independently calculated entitlement.
     *
     * @param  array{micro: string, recognized: int, capitalized: int}  $evidence
     */
    private function liabilitiesMatch(SavingsPlan $plan, array $evidence): bool
    {
        $principal = BigInteger::of($plan->contract['principal'])->plus($evidence['capitalized'])->toInt();
        $payable = $evidence['recognized'] - $evidence['capitalized'];
        if ($this->ledger->balance($this->plans->account($plan, 'principal'))->kobo !== -$principal
            || $this->ledger->balance($this->plans->account($plan, 'interest'))->kobo !== -$payable) {
            $this->accrual->investigate($plan, 'settlement', 'Plan liabilities disagree with principal, capitalizations, and accrued entitlement.');

            return false;
        }

        return true;
    }

    /**
     * Binds confirmation to the plan, lifecycle state, rollover election, and exact quoted amounts.
     *
     * @param  array<string, string>  $quote
     */
    private function token(SavingsPlan $plan, array $quote): string
    {
        return hash_hmac('sha256', json_encode([$plan->id, $plan->contract, (string) $plan->state, $plan->rollover_election, $quote], JSON_THROW_ON_ERROR), config('app.key'));
    }

    /**
     * Posts deductions and final transfers, then records the settlement, closure, waiver use, and notice.
     *
     * @param  array<string, string>  $quote
     */
    private function close(SavingsPlan $plan, array $quote, string $reason, ?int $approvalId = null): void
    {
        $date = in_array($reason, ['matured_withdrawal', 'rolled_over'], true)
            ? $plan->matures_at->setTimezone(config('app.timezone'))->toDateString() : $quote['quote_date'];
        $principalAccount = $this->plans->account($plan, 'principal');
        $interestAccount = $this->plans->account($plan, 'interest');
        $forfeitureJournal = $penaltyJournal = null;
        if ((int) $quote['forfeiture'] > 0) {
            $entries = [['account_id' => LedgerAccount::where('code', 'house:interest_expense')->sole()->id, 'amount' => new Money(-((int) $quote['forfeiture']))]];
            foreach ([[$principalAccount->id, $quote['forfeited_capitalized']], [$interestAccount->id, $quote['forfeited_payable']]] as [$id, $amount]) {
                if ((int) $amount > 0) {
                    $entries[] = ['account_id' => $id, 'amount' => new Money($amount)];
                }
            }
            $forfeitureJournal = $this->ledger->post('plan-forfeiture:'.$plan->id, $entries, $date, 'Savings interest forfeiture', $plan->user_id);
        }
        if ((int) $quote['principal_penalty'] > 0) {
            $penaltyJournal = $this->ledger->post('plan-penalty:'.$plan->id, [
                ['account_id' => $principalAccount->id, 'amount' => new Money($quote['principal_penalty'])],
                ['account_id' => LedgerAccount::where('code', 'house:penalty_income')->sole()->id, 'amount' => new Money(-((int) $quote['principal_penalty']))],
            ], $date, 'Savings original principal penalty', $plan->user_id);
        }
        $entries = [];
        foreach ([[$principalAccount->id, -$this->ledger->balance($principalAccount)->kobo], [$interestAccount->id, -$this->ledger->balance($interestAccount)->kobo],
            [LedgerAccount::where('code', 'house:withholding_tax_payable')->sole()->id, -((int) $quote['withholding'])],
            [LedgerAccount::where('code', 'customer:'.$plan->user_id.':reversal_receivable')->sole()->id, -((int) $quote['debt_repayment'])],
            [LedgerAccount::where('code', 'customer:'.$plan->user_id.':wallet')->sole()->id, -((int) $quote['wallet_credit'])]] as [$id, $amount]) {
            if ($amount !== 0) {
                $entries[] = ['account_id' => $id, 'amount' => new Money($amount)];
            }
        }
        // Complete forfeiture and a 100% principal penalty can leave no final transfer.
        $journal = $entries === [] ? ($penaltyJournal ?? $forfeitureJournal)
            : $this->ledger->post('plan-settlement:'.$plan->id, $entries, $date, 'Savings '.str_replace('_', ' ', $reason).' settlement', $plan->user_id);
        $statement = $quote;
        unset($statement['quote_date']);
        $statement['accrual_through'] = $quote['accrual_through'] === '' ? null : $quote['accrual_through'];
        $statement['waiver_id'] = $quote['waiver_id'] === '' ? null : (int) $quote['waiver_id'];
        $statement += ['savings_plan_id' => $plan->id, 'journal_id' => $journal->id, 'forfeiture_journal_id' => $forfeitureJournal?->id,
            'penalty_journal_id' => $penaltyJournal?->id, 'closure_reason' => $reason, 'approval_request_id' => $approvalId, 'posted_at' => $this->clock->now()];
        $settlementId = DB::table('plan_settlements')->insertGetId($statement);
        if ($plan->state instanceof Active && in_array($reason, ['matured_withdrawal', 'rolled_over'], true)) {
            $plan->state->transitionTo(Matured::class);
        }
        $plan->forceFill(['closure_reason' => $reason, 'closed_at' => $this->clock->now(), 'next_process_at' => null])->save();
        $plan->state->transitionTo(Closed::class);
        if ($statement['waiver_id'] !== null) {
            DB::table('plan_waivers')->where('id', $statement['waiver_id'])->whereNull('used_at')->update(['used_at' => $this->clock->now(), 'settlement_id' => $settlementId]);
            app(AuditTrail::class)->record('plan.waiver_used', $plan, ['waiver_id' => $statement['waiver_id'], 'settlement_id' => $settlementId]);
        }
        app(AuditTrail::class)->record('plan.closed', $plan, ['closure_reason' => $reason, 'settlement_id' => $settlementId, 'approval_id' => $approvalId]);
        $this->notices->record($plan, $statement);
    }

    /**
     * Catches up completed days and requires prepared entitlement before closure can proceed.
     */
    public function prepareClosure(SavingsPlan $plan): void
    {
        if (! $this->accrual->catchUp($plan)) {
            throw new DomainException('Interest history is catching up. Retry the closure after processing finishes.');
        }
        if ($this->accrual->entitlement($plan->contract, $this->accrual->completedDays($plan)) === null) {
            throw new DomainException('Interest calculation is being prepared. Retry the closure after processing finishes.');
        }
    }

    /**
     * Persists mismatch evidence separately after the financial operation has rolled back.
     */
    public function retainInvestigation(SavingsEvidenceMismatch $failure): void
    {
        $this->transactions->run([$failure->customerId], function () use ($failure): void {
            DB::table('savings_exceptions')->insertOrIgnore(['savings_plan_id' => $failure->planId,
                'operation' => $failure->operation, 'message' => $failure->getMessage(), 'created_at' => $this->clock->now()]);
        });
    }

    /**
     * Runs customer-locked closure work while retaining evidence mismatches outside its rollback.
     *
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    private function customerOperation(int $customerId, Closure $operation): mixed
    {
        try {
            return $this->transactions->run([$customerId], $operation);
        } catch (SavingsEvidenceMismatch $failure) {
            $this->retainInvestigation($failure);
            throw $failure;
        }
    }

    /**
     * Reloads customer eligibility and fetches the plan through its owner constraint.
     */
    private function owned(User $user, SavingsPlan $plan): SavingsPlan
    {
        $current = User::findOrFail($user->id);
        if (! $current->is_active || ! $current->hasVerifiedEmail()) {
            throw new DomainException('Only active verified customers can manage savings.');
        }

        return SavingsPlan::where('user_id', $user->id)->findOrFail($plan->id);
    }

    /**
     * Rejects closure while any retained savings exception requires investigation.
     */
    private function unblocked(SavingsPlan $plan): void
    {
        if (DB::table('savings_exceptions')->where('savings_plan_id', $plan->id)->exists()) {
            throw new DomainException('The plan requires investigation before closure.');
        }
    }
}
