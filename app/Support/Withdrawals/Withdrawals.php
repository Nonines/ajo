<?php

namespace App\Support\Withdrawals;

use App\Contracts\Clock;
use App\Contracts\PaymentGateway;
use App\Jobs\DispatchWithdrawal;
use App\Models\ApprovalRequest;
use App\Models\LedgerAccount;
use App\Models\PaymentException;
use App\Models\SavedDestination;
use App\Models\Staff;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Support\Audit\AuditTrail;
use App\Support\Auth\Approvals;
use App\Support\Ledger\FinancialTransaction;
use App\Support\Ledger\Ledger;
use App\Support\Ledger\RecoveryControls;
use App\Support\Money\Money;
use App\Support\Payments\GatewayFailure;
use App\Support\Payments\PaymentNotices;
use App\Support\Payments\PaymentSimulator;
use App\Support\Withdrawals\WithdrawalStates\Approved;
use App\Support\Withdrawals\WithdrawalStates\Cancelled;
use App\Support\Withdrawals\WithdrawalStates\Failed;
use App\Support\Withdrawals\WithdrawalStates\Paid;
use App\Support\Withdrawals\WithdrawalStates\QueuedForPayout;
use App\Support\Withdrawals\WithdrawalStates\Rejected;
use App\Support\Withdrawals\WithdrawalStates\Requested;
use App\Support\Withdrawals\WithdrawalStates\Reversed;
use Brick\Math\BigInteger;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Reserves wallet funds and reconciles payouts against immutable approval and provider evidence.
 */
class Withdrawals
{
    public function __construct(private FinancialTransaction $transactions, private Ledger $ledger,
        private Clock $clock, private PaymentGateway $gateway, private AuditTrail $audit) {}

    /**
     * Selects the configured whole-kobo processing fee for a principal amount.
     */
    public function quote(Money $amount): Money
    {
        if ($amount->kobo <= 0) {
            throw new DomainException('A withdrawal must be positive.');
        }

        foreach (config('withdrawals.fee_tiers') as [$ceiling, $fee]) {
            if ($amount->kobo <= $ceiling) {
                $quote = new Money($fee);

                if ($quote->kobo < 0) {
                    throw new DomainException('The configured withdrawal fee must be nonnegative.');
                }

                return $quote;
            }
        }

        throw new DomainException('A withdrawal fee quote is unavailable.');
    }

    /**
     * Snapshots destination, fee, and review threshold while reserving principal and fee once per request key.
     */
    public function request(User $user, SavedDestination $destination, Money $principal, string $requestKey, ?Staff $simulationActor = null): WithdrawalRequest
    {
        if (! Str::isUuid($requestKey)) {
            throw new DomainException('A unique withdrawal request key is required.');
        }

        if ($simulationActor !== null) {
            app(PaymentSimulator::class)->authorize($simulationActor);
        }

        $origin = $simulationActor === null ? 'real' : 'simulated';

        return $this->transactions->run([$user->id], function () use ($user, $destination, $principal, $requestKey, $origin): WithdrawalRequest {
            $this->eligible($user->id);
            $saved = SavedDestination::where('user_id', $user->id)->findOrFail($destination->id);

            if ($saved->origin !== $origin) {
                throw new DomainException('Destination adapter does not match the authorized withdrawal origin.');
            }

            // A request-key replay must retain its original owner, destination, amount, and reservation.
            $existing = WithdrawalRequest::where('request_key', $requestKey)->first();

            if ($existing !== null) {
                if ($existing->user_id !== $user->id || $existing->saved_destination_id !== $saved->id || $existing->principal !== $principal->kobo) {
                    throw new DomainException('Withdrawal request key was already used with different details.');
                }

                if ($existing->reservation_journal_id === null) {
                    throw new DomainException('The withdrawal has no committed reservation evidence.');
                }

                return $existing;
            }

            // Freeze the fee and review threshold before reserving customer funds.
            $fee = $this->quote($principal);
            $reservation = BigInteger::of($principal->kobo)->plus($fee->kobo);

            if ($reservation->isGreaterThan(PHP_INT_MAX)) {
                throw new DomainException('The withdrawal and fee exceed supported integer kobo.');
            }

            $total = $reservation->toInt();
            $threshold = new Money(config('withdrawals.threshold'));

            if ($threshold->kobo <= 0 || config('withdrawals.review_hours') < 1) {
                throw new DomainException('Withdrawal approval configuration is invalid.');
            }

            $withdrawal = new WithdrawalRequest;
            $withdrawal->forceFill(['user_id' => $user->id, 'saved_destination_id' => $saved->id, 'request_key' => $requestKey,
                'reference' => 'ajo-'.strtolower((string) Str::uuid()), 'origin' => $origin, 'destination' => $saved->details,
                'principal' => $principal->kobo, 'reserved_fee' => $fee->kobo, 'threshold' => $threshold->kobo,
                'state' => Requested::class, 'created_at' => $this->clock->now(),
                'expires_at' => $this->clock->now()->addHours(config('withdrawals.review_hours'))])->save();
            $journal = $this->ledger->post('withdrawal:'.$withdrawal->id.':reserve', [
                ['account_id' => $this->account('customer:'.$user->id.':wallet'), 'amount' => new Money($total)],
                ['account_id' => $this->account('clearing:payouts_in_transit'), 'amount' => new Money(-$total)],
            ], $this->date(), 'Withdrawal principal and fee reservation', $user->id);
            $withdrawal->forceFill(['reservation_journal_id' => $journal->id])->save();
            $this->audit->record('withdrawal.requested', $withdrawal, ['principal' => (string) $principal->kobo,
                'reserved_fee' => (string) $fee->kobo, 'threshold' => (string) $threshold->kobo], $user);
            app(PaymentNotices::class)->withdrawal($withdrawal);

            return $withdrawal;
        });
    }

    /**
     * Allows single-staff approval at or below the reserved-value threshold, or rejection while awaiting review.
     */
    public function review(Staff $staff, WithdrawalRequest $withdrawal, bool $approve, string $reason): WithdrawalRequest
    {
        return $this->transactions->run([$withdrawal->user_id], function () use ($staff, $withdrawal, $approve, $reason): WithdrawalRequest {
            $reviewer = app(Approvals::class)->authorize($staff, 'withdrawals.review');
            $current = WithdrawalRequest::findOrFail($withdrawal->id);

            if ($this->expire($current)) {
                return $current->refresh();
            }

            if (! in_array((string) $current->state, ['requested', 'under_review'], true)) {
                throw new DomainException('This withdrawal is no longer awaiting review.');
            }

            if (trim($reason) === '') {
                throw new DomainException('A review reason is required.');
            }

            if (! $approve) {
                $this->release($current, 'rejected', $reason);

                return $current->refresh();
            }

            if ($this->requiresChecker($current)) {
                throw new DomainException('This reservation requires a maker and a different checker.');
            }

            $this->approve($current, $reviewer);

            return $current->refresh();
        });
    }

    /**
     * Compares total reserved value with the snapshotted threshold for two-staff approval.
     */
    public function requiresChecker(WithdrawalRequest $withdrawal): bool
    {
        return BigInteger::of($withdrawal->principal)->plus($withdrawal->reserved_fee)->isGreaterThan($withdrawal->threshold);
    }

    /**
     * Rechecks reservation and staff evidence before queuing payout dispatch after commit.
     */
    public function approve(WithdrawalRequest $withdrawal, Staff $staff, ?ApprovalRequest $approval = null): void
    {
        $withdrawal = WithdrawalRequest::findOrFail($withdrawal->id);
        $this->transactions->run([$withdrawal->user_id], function () use ($withdrawal, $staff, $approval): void {
            $withdrawal->refresh();

            if ($withdrawal->reservation_journal_id === null) {
                throw new DomainException('The withdrawal has no committed reservation evidence.');
            }

            $staff = app(Approvals::class)->authorize($staff, $this->requiresChecker($withdrawal) ? 'withdrawals.check' : 'withdrawals.review');
            $approval = $approval === null ? null : ApprovalRequest::findOrFail($approval->id);
            $this->eligible($withdrawal->user_id);

            if ($withdrawal->expires_at->lessThanOrEqualTo($this->clock->now()) || $withdrawal->submitted_at !== null
                || ! in_array((string) $withdrawal->state, ['requested', 'under_review'], true)) {
                throw new DomainException('The withdrawal can no longer be approved.');
            }

            // Larger reservations require the decision attached to this exact maker proposal.
            if ($this->requiresChecker($withdrawal)) {
                if ($approval === null || $approval->state !== 'approved' || $approval->action !== 'withdrawal'
                    || $approval->subject_type !== WithdrawalRequest::class || $approval->subject_id !== $withdrawal->id
                    || $approval->id !== $withdrawal->approval_request_id || $approval->decided_by !== $staff->id) {
                    throw new DomainException('A distinct checker approval is required.');
                }
            } else {
                app(Approvals::class)->authorize($staff, 'withdrawals.review');
            }

            // Commit approval and queued payout intent before attempting dispatch.
            $withdrawal->forceFill(['reviewed_by' => $staff->id, 'approved_at' => $this->clock->now()])->save();
            $withdrawal->state->transitionTo(Approved::class);
            $withdrawal->state->transitionTo(QueuedForPayout::class);
            $this->audit->record('withdrawal.approved', $withdrawal, ['approval_id' => $approval?->id], $staff);
            app(PaymentNotices::class)->withdrawal($withdrawal);
            DB::afterCommit(function () use ($withdrawal): void {
                try {
                    DispatchWithdrawal::dispatch($withdrawal->id);
                } catch (Throwable) {
                    // The scheduler rediscovers approved, unsubmitted requests.
                }
            });
        });
    }

    /**
     * Releases an owned request only before final staff approval.
     */
    public function cancel(User $user, WithdrawalRequest $withdrawal): void
    {
        $this->transactions->run([$user->id], function () use ($user, $withdrawal): void {
            $current = WithdrawalRequest::where('user_id', $user->id)->findOrFail($withdrawal->id);

            if ($current->state instanceof Cancelled) {
                return;
            }

            if ($current->approved_at !== null || ! in_array((string) $current->state, ['requested', 'under_review'], true)) {
                throw new DomainException('Customer cancellation ends at final staff approval.');
            }

            $this->release($current, 'cancelled', 'Customer cancelled before final approval');
        });
    }

    /**
     * Releases requests whose review window elapsed before approval.
     */
    public function expire(WithdrawalRequest $withdrawal): bool
    {
        if (in_array((string) $withdrawal->state, ['requested', 'under_review'], true)
            && $withdrawal->expires_at->lessThanOrEqualTo($this->clock->now())) {
            $this->release($withdrawal, 'cancelled', 'Review deadline expired');

            return true;
        }

        return false;
    }

    /**
     * Returns an unsubmitted reservation through debt-first credits and cancels pending proposals.
     */
    public function release(WithdrawalRequest $withdrawal, string $state, string $reason): void
    {
        $this->transactions->run([$withdrawal->user_id], function () use ($withdrawal, $state, $reason): void {
            $current = WithdrawalRequest::findOrFail($withdrawal->id);

            if (in_array((string) $current->state, ['cancelled', 'rejected'], true)) {
                return;
            }

            if ($current->submitted_at !== null || ! in_array((string) $current->state, ['requested', 'under_review', 'approved', 'queued_for_payout'], true)) {
                throw new DomainException('Submitted or uncertain reservations cannot be cancelled.');
            }

            if ($current->reservation_journal_id === null) {
                throw new DomainException('The withdrawal has no committed reservation evidence.');
            }

            if (! in_array($state, ['cancelled', 'rejected'], true)) {
                throw new DomainException('Invalid unused-reservation release.');
            }

            // Return unused funds through debt-first credits rather than an unconditional wallet refund.
            $amount = BigInteger::of($current->principal)->plus($current->reserved_fee)->toInt();
            $this->ledger->post('withdrawal:'.$current->id.':release', array_merge([
                ['account_id' => $this->account('clearing:payouts_in_transit'), 'amount' => new Money($amount)],
            ], app(RecoveryControls::class)->credits($current->user_id, $amount)), $this->date(), 'Unused withdrawal reservation released', $current->user_id);
            $current->state->transitionTo($state === 'cancelled' ? Cancelled::class : Rejected::class);
            ApprovalRequest::where('subject_type', WithdrawalRequest::class)->where('subject_id', $current->id)
                ->where('state', 'pending')->update(['state' => 'cancelled']);
            $this->audit->record('withdrawal.'.$state, $current, ['reason' => $reason]);
            app(PaymentNotices::class)->withdrawal($current);
        });
    }

    /**
     * Commits submission intent before contacting the gateway outside the customer lock.
     */
    public function dispatch(WithdrawalRequest $withdrawal): void
    {
        // Commit submission intent once, then contact the provider outside the customer lock.
        $send = $this->transactions->run([$withdrawal->user_id], function () use ($withdrawal): bool {
            $current = WithdrawalRequest::findOrFail($withdrawal->id);

            if (! $current->state instanceof QueuedForPayout || $current->submitted_at !== null) {
                return false;
            }

            try {
                $this->eligible($current->user_id);
            } catch (DomainException) {
                $this->release($current, 'cancelled', 'Customer is no longer eligible for payout');

                return false;
            }

            if ($this->quote(new Money($current->principal))->kobo > $current->reserved_fee) {
                $this->release($current, 'cancelled', 'The fee increased before dispatch; request a new quote');

                return false;
            }

            $current->forceFill(['submitted_at' => $this->clock->now(), 'last_dispatch_at' => $this->clock->now(), 'next_verify_at' => $this->clock->now()->addMinutes(15)])->save();
            $this->audit->record('withdrawal.submission_intent', $current, ['reference' => $current->reference]);

            return true;
        });

        if (! $send) {
            return;
        }

        try {
            // The callback must be able to acquire the customer lock before this POST returns.
            $this->gateway->transfer($withdrawal->refresh());
            $this->verify($withdrawal);
        } catch (Throwable) {
            PaymentException::record('payout_uncertain', 'Payout submission has no verified final outcome; retain its reservation.',
                $this->clock->now(), resourceType: 'withdrawal', resourceId: (string) $withdrawal->id);
        }
    }

    /**
     * Approves only a matching submitted reservation with the expected origin, amount, and recipient.
     *
     * @param  array<string, mixed>  $details
     */
    public function providerApproval(array $details, string $origin = 'real'): bool
    {
        $reference = $details['reference'] ?? null;
        $withdrawal = is_string($reference) ? WithdrawalRequest::where('reference', $reference)->first() : null;

        if ($withdrawal === null || $withdrawal->origin !== $origin || ! in_array($origin, ['real', 'simulated'], true)) {
            return false;
        }

        return $this->transactions->run([$withdrawal->user_id], function () use ($withdrawal, $details): bool {
            $current = $withdrawal->refresh();

            try {
                $this->eligible($current->user_id);
                $amount = new Money($details['amount'] ?? null);
            } catch (DomainException|\InvalidArgumentException) {
                return false;
            }

            if (! $current->state instanceof QueuedForPayout || $current->approved_at === null || $current->submitted_at === null
                || $amount->kobo !== $current->principal || ($details['recipient'] ?? null) !== $current->destination['recipient_code']
                || ($details['currency'] ?? null) !== 'NGN' || ($details['source'] ?? null) !== 'balance') {
                return false;
            }

            if ($current->provider_approved_at === null) {
                $current->forceFill(['provider_approved_at' => $this->clock->now()])->save();
                $this->audit->record('withdrawal.provider_approved', $current);
            }

            return true;
        });
    }

    /**
     * Matches provider evidence before posting principal outcomes and reconciling verified fees.
     */
    public function verify(WithdrawalRequest $withdrawal): void
    {
        $verified = $this->gateway->verifyTransfer($withdrawal->reference);
        $this->transactions->run([$withdrawal->user_id], function () use ($withdrawal, $verified): void {
            $current = $withdrawal->refresh();

            if ($current->submitted_at === null || $verified->reference !== $current->reference || $verified->domain !== 'test'
                || $verified->currency !== 'NGN' || $verified->amount !== $current->principal
                || $verified->recipientCode !== $current->destination['recipient_code']
                || ($current->provider_id !== null && $current->provider_id !== $verified->id)) {
                throw new DomainException('Verified transfer does not match its immutable local reservation.');
            }

            // Retain verified observations before interpreting final principal outcomes.
            $current->forceFill(['provider_id' => $verified->id, 'provider_status' => $verified->status,
                'last_verified_at' => $this->clock->now(), 'next_verify_at' => $this->clock->now()->addMinutes(5),
                'verification' => ['status' => $verified->status, 'amount' => (string) $verified->amount,
                    'fee' => $verified->fee === null ? null : (string) $verified->fee]])->save();

            // Separate inconclusive observations from success, failure, and returned principal.
            $success = $verified->status === 'success';
            $returned = $verified->status === 'reversed';
            $failed = in_array($verified->status, ['failed', 'rejected', 'blocked', 'abandoned'], true);

            if (! $success && ! $returned && ! $failed) {
                return;
            }

            if ($success && $current->provider_approved_at === null) {
                $this->investigate($current, 'payout_approval_missing', 'Provider reports success without local server-approval evidence.');

                return;
            }

            if ($current->state instanceof Paid && $failed) {
                $this->investigate($current, 'payout_conflict', 'A failed observation conflicts with an already verified successful payout.');

                return;
            }

            if (($current->state instanceof Failed || $current->state instanceof Reversed) && $success) {
                $this->investigate($current, 'payout_conflict', 'A success observation conflicts with a conclusively returned reservation.');

                return;
            }

            // Post principal once; a later confirmed return receives a separate journal.
            if ($current->principal_journal_id === null) {
                $entries = [['account_id' => $this->account('clearing:payouts_in_transit'), 'amount' => new Money($current->principal)]];
                $entries = array_merge($entries, $success
                    ? [['account_id' => $this->account('house:cash_at_gateway'), 'amount' => new Money(-$current->principal)]]
                    : app(RecoveryControls::class)->credits($current->user_id, $current->principal));
                $journal = $this->ledger->post('withdrawal:'.$current->id.':principal', $entries, $this->date(),
                    $success ? 'Verified wallet payout' : 'Verified unused payout principal returned', $current->user_id);
                $current->forceFill(['principal_journal_id' => $journal->id])->save();
                $current->state->transitionTo($success ? Paid::class : ($returned ? Reversed::class : Failed::class));
            } elseif ($current->state instanceof Paid && $returned && $current->return_journal_id === null) {
                $journal = $this->ledger->post('withdrawal:'.$current->id.':return', array_merge([
                    ['account_id' => $this->account('house:cash_at_gateway'), 'amount' => new Money($current->principal)],
                ], app(RecoveryControls::class)->credits($current->user_id, $current->principal)), $this->date(), 'Verified payout principal returned', $current->user_id);
                $current->forceFill(['return_journal_id' => $journal->id])->save();
                $current->state->transitionTo(Reversed::class);
            }

            // Unknown fees retain their reservation; verified revisions reconcile independently of principal.
            if ($verified->fee === null) {
                $this->investigate($current, 'payout_fee_unknown', 'The actual incurred payout fee is not verified; retain the fee reservation.');
            } else {
                $current->forceFill(['verified_fee' => $verified->fee])->save();

                try {
                    $this->ledger->settleWithdrawalFee($current);
                } catch (Throwable) {
                    $this->investigate($current, 'payout_fee_posting', 'Verified payout outcome is retained; fee accounting requires investigation and retry.');
                }
            }

            $this->audit->record('withdrawal.verified', $current, ['status' => $verified->status, 'fee' => $verified->fee]);
            app(PaymentNotices::class)->withdrawal($current->refresh());

            if ($current->accounted_fee !== null && $current->accounted_fee === $current->verified_fee) {
                $current->forceFill(['next_verify_at' => $current->state instanceof Paid ? $this->clock->now()->addDay() : null])->save();
            }

            PaymentException::where('resource_type', 'withdrawal')->where('resource_id', (string) $current->id)
                ->whereIn('code', ['payout_uncertain', 'payout_unresolved'])->update(['resolved_at' => $this->clock->now()]);
        });
    }

    /**
     * Verifies first, retrying an eligible inconclusive submission with its original reference.
     */
    public function reconcile(WithdrawalRequest $withdrawal): void
    {
        try {
            $this->verify($withdrawal);
        } catch (GatewayFailure) {
            $retry = $this->transactions->run([$withdrawal->user_id], function () use ($withdrawal): bool {
                $current = $withdrawal->refresh();

                if (! $current->state instanceof QueuedForPayout || $current->submitted_at === null
                    || ($current->last_dispatch_at ?? $current->submitted_at)->addMinutes(15)->greaterThan($this->clock->now())
                    || app(RecoveryControls::class)->restricted($current->user_id)
                    || $this->quote(new Money($current->principal))->kobo > $current->reserved_fee) {
                    return false;
                }

                $this->eligible($current->user_id);
                $current->forceFill(['last_dispatch_at' => $this->clock->now()])->save();
                $this->audit->record('withdrawal.retry_intent', $current, ['reference' => $current->reference]);

                return true;
            });

            if ($retry) {
                // Paystack documents retrying non-conclusive transfers with the original unique reference.
                $this->gateway->transfer($withdrawal->refresh());
                $this->verify($withdrawal);
            }
        }
    }

    /**
     * Requires an active, verified customer without outstanding recovery debt.
     */
    private function eligible(int $customerId): void
    {
        $customer = User::findOrFail($customerId);

        if (! $customer->is_active || ! $customer->hasVerifiedEmail() || app(RecoveryControls::class)->restricted($customerId)) {
            throw new DomainException('Payout requires an active verified customer without recovery debt.');
        }
    }

    /**
     * Resolves the exact ledger account required by a payout posting.
     */
    private function account(string $code): int
    {
        return LedgerAccount::where('code', $code)->sole()->id;
    }

    /**
     * Uses the application timezone for the payout's economic effective date.
     */
    private function date(): string
    {
        return $this->clock->now()->setTimezone(config('app.timezone'))->toDateString();
    }

    /**
     * Retains payout-specific evidence failures for staff investigation.
     */
    private function investigate(WithdrawalRequest $withdrawal, string $code, string $message): void
    {
        PaymentException::record($code, $message, $this->clock->now(), resourceType: 'withdrawal', resourceId: (string) $withdrawal->id);
    }
}
