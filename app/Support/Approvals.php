<?php

namespace App\Support;

use App\Contracts\Clock;
use App\Models\ApprovalRequest;
use App\Models\LedgerAccount;
use App\Models\SavingsPlan;
use App\Models\SavingsProduct;
use App\Models\Staff;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Support\PlanStates\Active;
use App\Support\PlanStates\Suspended;
use App\Support\WithdrawalStates\UnderReview;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class Approvals
{
    private const array PERMISSIONS = [
        'withdrawal' => ['withdrawals.make', 'withdrawals.check'],
        'adjustment' => ['adjustments.make', 'adjustments.check'],
        'product.rate' => ['rates.make', 'rates.check'],
        'plan.hold' => ['plans.hold', 'plans.hold'],
        'plan.release' => ['plans.release', 'plans.release'],
        'plan.waive' => ['waivers.make', 'waivers.check'],
        'plan.close' => ['plans.close', 'plans.close'],
        'recovery.release' => ['recovery.release', 'recovery.release'],
        'recovery.write-off' => ['recovery.write-off', 'recovery.write-off'],
    ];

    public function __construct(private FinancialTransaction $transactions, private Clock $clock, private AuditTrail $audit) {}

    public function authorize(Staff $staff, string $permission): Staff
    {
        $current = Staff::findOrFail($staff->id);
        if (! $current->is_active || ! $current->can($permission)) {
            throw new AuthorizationException('The staff permission is required.');
        }

        return $current;
    }

    /** @param array<string, mixed> $payload */
    public function propose(Staff $staff, string $action, Model $subject, array $payload, string $reason): ApprovalRequest
    {
        $permissions = self::PERMISSIONS[$action] ?? throw new DomainException('Unsupported approval action.');
        $this->authorize($staff, $permissions[0]);
        if (trim($reason) === '' || mb_strlen($reason) > 10000) {
            throw new DomainException('A concise approval reason is required.');
        }
        $expected = match ($action) {
            'withdrawal' => WithdrawalRequest::class, 'product.rate' => SavingsProduct::class,
            'plan.hold', 'plan.release', 'plan.waive', 'plan.close' => SavingsPlan::class, default => User::class,
        };
        if (! $subject instanceof $expected) {
            throw new DomainException('Approval subject does not match the action.');
        }

        return $this->transactions->run($this->customers($subject), function () use ($staff, $action, $subject, $payload, $reason): ApprovalRequest {
            $subject->refresh();
            $expires = $this->clock->now()->addHours(config('withdrawals.approval_hours'));
            $expired = false;
            if ($subject instanceof WithdrawalRequest) {
                $expired = app(Withdrawals::class)->expire($subject);
                if (! $expired && (! app(Withdrawals::class)->requiresChecker($subject) || ! in_array((string) $subject->state, ['requested', 'under_review'], true)
                    || $subject->approval_request_id !== null || app(RecoveryControls::class)->restricted($subject->user_id))) {
                    throw new DomainException('Withdrawal is not eligible for a maker proposal.');
                }
                $payload = ['reservation_journal_id' => $subject->reservation_journal_id];
                $expires = $subject->expires_at;
            } elseif ($subject instanceof SavingsProduct) {
                $payload['expected_version'] = $subject->current_version;
            } elseif ($subject instanceof SavingsPlan) {
                if ($action === 'plan.waive') {
                    $payload = app(SavingsSettlements::class)->waiverTerms($subject, $payload);
                } elseif ($action === 'plan.close') {
                    app(SavingsSettlements::class)->validateAdministrative($subject, $payload);
                    $payload = ['correction' => $payload['correction'], 'evidence' => $payload['evidence']];
                } else {
                    $payload = [];
                    $expected = $action === 'plan.hold' ? 'active' : 'suspended';
                    if ((string) $subject->state !== $expected) {
                        throw new DomainException('Plan state is ineligible for the proposed action.');
                    }
                }
                $payload['expected_state'] = (string) $subject->state;
            }
            $approval = new ApprovalRequest;
            $approval->forceFill(['subject_type' => $subject->getMorphClass(), 'subject_id' => $subject->getKey(),
                'action' => $action, 'payload' => $payload, 'requested_by' => $staff->id,
                'state' => $expired ? 'expired' : 'pending', 'reason' => $reason, 'expires_at' => $expires,
                'created_at' => $this->clock->now()])->save();
            if ($subject instanceof WithdrawalRequest && ! $expired) {
                $subject->forceFill(['approval_request_id' => $approval->id])->save();
                $subject->state->transitionTo(UnderReview::class);
            }
            $this->audit->record('approval.proposed', $approval, ['action' => $action, 'subject_type' => $subject->getMorphClass(),
                'subject_id' => $subject->getKey(), 'customer_id' => $this->customers($subject)[0] ?? null, 'reason' => $reason], $staff);

            return $approval;
        });
    }

    public function decide(Staff $staff, ApprovalRequest $approval, bool $accept, string $reason): ApprovalRequest
    {
        $approval = ApprovalRequest::findOrFail($approval->id);
        $subject = $this->subject($approval);

        if ($accept && $approval->action === 'plan.close' && $subject instanceof SavingsPlan && $approval->state === 'pending') {
            $checker = $this->authorize($staff, 'plans.close');
            if ($approval->requested_by === $checker->id) {
                throw new AuthorizationException('The requester cannot decide their own proposal.');
            }
            $this->authorize(Staff::findOrFail($approval->requested_by), 'plans.close');
            if ($approval->expires_at->greaterThan($this->clock->now())) {
                app(SavingsSettlements::class)->validateAdministrative($subject, $approval->payload);
                app(SavingsSettlements::class)->prepareClosure($subject);
            }
        }

        try {
            return $this->transactions->run($this->customers($subject), function () use ($staff, $approval, $accept, $reason): ApprovalRequest {
                $current = $approval->refresh();
                $permissions = self::PERMISSIONS[$current->action] ?? throw new DomainException('Unsupported approval.');
                $checker = $this->authorize($staff, $permissions[1]);
                if ($current->requested_by === $checker->id) {
                    throw new AuthorizationException('The requester cannot decide their own proposal.');
                }
                if ($current->state !== 'pending') {
                    throw new DomainException('This proposal no longer awaits a decision.');
                }
                if (trim($reason) === '') {
                    throw new DomainException('A decision reason is required.');
                }
                if ($this->expire($current)) {
                    return $current->refresh();
                }
                $this->authorize(Staff::findOrFail($current->requested_by), $permissions[0]);
                $subject = $this->subject($current);
                $current->forceFill(['state' => $accept ? 'approved' : 'rejected', 'decided_by' => $checker->id,
                    'decision_reason' => $reason, 'decided_at' => $this->clock->now()])->save();
                if ($accept) {
                    $evidence = $this->apply($current, $checker, $subject);
                    $current->forceFill(['applied_at' => $this->clock->now(), 'evidence' => $evidence])->save();
                } elseif ($subject instanceof WithdrawalRequest) {
                    app(Withdrawals::class)->release($subject, 'rejected', $reason);
                }
                if (! $accept && $current->action === 'plan.waive' && $subject instanceof SavingsPlan) {
                    app(SavingsNotices::class)->event($subject, 'savings_waiver_rejected', ['approval_id' => (string) $current->id], 'waiver-proposal:'.$current->id.':rejected');
                }
                $this->audit->record('approval.'.$current->state, $current, ['reason' => $reason], $checker);

                return $current;
            });
        } catch (SavingsEvidenceMismatch $failure) {
            app(SavingsSettlements::class)->retainInvestigation($failure);
            throw $failure;
        }
    }

    public function expire(ApprovalRequest $approval): bool
    {
        if ($approval->state !== 'pending' || $approval->expires_at->greaterThan($this->clock->now())) {
            return false;
        }
        $subject = $this->subject($approval);
        if ($subject instanceof WithdrawalRequest && $subject->submitted_at === null
            && in_array((string) $subject->state, ['requested', 'under_review'], true)) {
            app(Withdrawals::class)->release($subject, 'cancelled', 'Review deadline expired');
        }
        $approval->forceFill(['state' => 'expired'])->save();
        if ($approval->action === 'plan.waive' && $subject instanceof SavingsPlan) {
            app(SavingsNotices::class)->event($subject, 'savings_waiver_expired', ['approval_id' => (string) $approval->id], 'waiver-proposal:'.$approval->id.':expired');
        }
        $this->audit->record('approval.expired', $approval);

        return true;
    }

    /** @return array<string, mixed> */
    private function apply(ApprovalRequest $approval, Staff $checker, Model $subject): array
    {
        if ($subject instanceof WithdrawalRequest) {
            if ($approval->payload['reservation_journal_id'] !== $subject->reservation_journal_id) {
                throw new DomainException('The proposed reservation no longer matches.');
            }
            app(Withdrawals::class)->approve($subject, $checker, $approval);

            return ['withdrawal_id' => $subject->id];
        }
        if ($subject instanceof SavingsProduct) {
            if ($subject->current_version !== $approval->payload['expected_version']) {
                throw new DomainException('Product changed after this rate proposal.');
            }
            $product = app(SavingsProducts::class)->publish($checker, $approval->payload['data'], $subject, $approval);

            return ['version' => $product->current_version];
        }
        if ($subject instanceof SavingsPlan) {
            if ((string) $subject->state !== $approval->payload['expected_state']) {
                throw new DomainException('Plan changed after this proposal.');
            }
            if ($approval->action === 'plan.waive') {
                return ['waiver_id' => app(SavingsSettlements::class)->issueWaiver($subject, $approval)];
            }
            if ($approval->action === 'plan.close') {
                app(SavingsSettlements::class)->administrative($subject, $approval);

                return ['settlement_id' => DB::table('plan_settlements')->where('savings_plan_id', $subject->id)->sole()->id];
            }
            $subject->state->transitionTo($approval->action === 'plan.hold' ? Suspended::class : Active::class);
            if ($approval->action === 'plan.release') {
                $subject->forceFill(['next_process_at' => $this->clock->now()])->save();
            }
            $this->audit->record($approval->action, $subject, ['approval_id' => $approval->id], $checker);

            return ['state' => (string) $subject->state];
        }
        if (! $subject instanceof User) {
            throw new DomainException('Unsupported approval subject.');
        }
        $recovery = app(RecoveryControls::class);
        $effectiveDate = $this->clock->now()->setTimezone('Africa/Lagos')->toDateString();
        if ($approval->action === 'recovery.release') {
            if ($recovery->balance($subject->id) !== 0) {
                throw new DomainException('Recovery release requires a zero debt balance.');
            }

            return ['debt' => '0'];
        }
        if ($approval->action === 'recovery.write-off') {
            $amount = new Money($approval->payload['amount'] ?? null);
            if ($amount->kobo <= 0 || $amount->kobo > $recovery->balance($subject->id)) {
                throw new DomainException('Write-off exceeds outstanding recovery debt.');
            }
            $entries = [
                ['account_id' => LedgerAccount::where('code', 'customer:'.$subject->id.':reversal_receivable')->sole()->id, 'amount' => $amount->negate()],
                ['account_id' => LedgerAccount::where('code', 'house:reversal_write_off_expense')->sole()->id, 'amount' => $amount],
            ];
        } else {
            $values = Validator::make($approval->payload, [
                'entries' => ['required', 'array', 'min:2'], 'entries.*.account_id' => ['required', 'integer', 'distinct'],
                'entries.*.amount' => ['required', 'regex:/^-?[0-9]+$/D'], 'effective_date' => ['required', 'date_format:Y-m-d'],
            ])->validate();
            $effectiveDate = $values['effective_date'];
            $entries = [];
            foreach ($values['entries'] as $line) {
                $account = LedgerAccount::whereKey($line['account_id'])->sole();
                $amount = new Money($line['amount']);
                if ($account->user_id !== null && ($account->user_id !== $subject->id || $account->code !== 'customer:'.$subject->id.':wallet')) {
                    throw new DomainException('Manual adjustments cannot alter plan contracts or bypass recovery write-off controls.');
                }
                if ($account->user_id === $subject->id && $amount->kobo < 0) {
                    $entries = array_merge($entries, $recovery->credits($subject->id, -$amount->kobo));
                } else {
                    $entries[] = ['account_id' => $account->id, 'amount' => $amount];
                }
            }
        }
        $journal = app(Ledger::class)->post('approval:'.$approval->id.':apply', $entries,
            $effectiveDate,
            'Approved '.$approval->action, $subject->id);

        return ['journal_id' => $journal->id];
    }

    private function subject(ApprovalRequest $approval): Model
    {
        $class = $approval->subject_type;
        if (! in_array($class, [WithdrawalRequest::class, SavingsPlan::class, SavingsProduct::class, User::class], true)) {
            throw new DomainException('Unsupported approval subject type.');
        }

        return $class::findOrFail($approval->subject_id);
    }

    /** @return list<int> */
    private function customers(Model $subject): array
    {
        return $subject instanceof User ? [$subject->id] : ($subject instanceof SavingsPlan || $subject instanceof WithdrawalRequest ? [$subject->user_id] : []);
    }
}
