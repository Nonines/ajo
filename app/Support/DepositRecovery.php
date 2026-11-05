<?php

namespace App\Support;

use App\Contracts\Clock;
use App\Contracts\PaymentGateway;
use App\Models\Deposit;
use App\Models\PaymentException;
use App\Models\PaymentReversal;
use App\Support\DepositStates\Reversed;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\Cache;

class DepositRecovery
{
    public function __construct(private PaymentGateway $gateway, private FinancialTransaction $transactions, private Ledger $ledger, private Clock $clock, private PaymentNotices $notices) {}

    public function refund(string $id): void
    {
        $refund = $this->gateway->refund($id);
        $deposit = Deposit::where('provider_id', $refund->transactionId)->first();
        if ($deposit === null) {
            PaymentException::record('orphan_refund', 'Verified refund has no recognized local provider transaction.', $this->clock->now(), resourceType: 'refund', resourceId: $id, providerOpen: true);

            return;
        }
        $payment = $this->gateway->verify($deposit->reference);
        if ($payment->id !== $refund->transactionId || $payment->reference !== $deposit->reference || strcasecmp($payment->email, $deposit->payer_email) !== 0 || $payment->domain !== 'test' || $payment->currency !== 'NGN') {
            throw new DomainException('Refund transaction association could not be verified.');
        }
        $this->transactions->run([$deposit->user_id], function () use ($deposit, $refund, $payment): void {
            $current = Deposit::findOrFail($deposit->id);
            if ($current->recognized_at !== null && ($payment->gross !== $current->gross_amount || $payment->fees !== $current->fee_amount)) {
                throw new DomainException('Refund transaction amounts conflict with original recognition.');
            }
            PaymentException::where('code', 'orphan_refund')->where('resource_type', 'refund')->where('resource_id', $refund->id)->update(['resolved_at' => $this->clock->now(), 'provider_open' => false]);
            if ($refund->domain !== 'test' || $refund->currency !== 'NGN' || $refund->amount <= 0
                || $refund->status !== 'processed' || ! $refund->fullyDeducted || $refund->deducted !== $refund->amount) {
                PaymentException::record('refund_evidence_required', 'Refund is open or its actual financial deduction is not established.', $this->clock->now(), $current->id, resourceType: 'refund', resourceId: $refund->id, providerOpen: true);

                return;
            }
            $fingerprint = hash('sha256', json_encode([$refund->id, $refund->transactionId, $refund->domain, $refund->currency, $refund->amount, $refund->deducted], JSON_THROW_ON_ERROR));
            $existing = PaymentReversal::where('provider_refund_id', $refund->id)->first();
            if ($existing !== null) {
                if ($existing->deposit_id !== $current->id || ! hash_equals($existing->fingerprint, $fingerprint)) {
                    PaymentException::record('refund_mismatch', 'Provider refund changed after its original verified recovery.', $this->clock->now(), $current->id, resourceType: 'refund', resourceId: $refund->id);

                    return;
                }
            }
            if ($current->recognition_journal_id === null) {
                $existing ??= new PaymentReversal;
                $existing->forceFill(['deposit_id' => $current->id, 'provider_refund_id' => $refund->id, 'provider_dispute_id' => $refund->disputeId,
                    'fingerprint' => $fingerprint, 'amount' => $refund->amount, 'evidence' => $refund->evidence, 'verified_at' => $this->clock->now()])->save();
                $refunded = PaymentReversal::where('deposit_id', $current->id)->whereNull('journal_id')->sum('amount');
                if ($payment->status === 'reversed' && $payment->gross > 0 && $payment->fees >= 0
                    && $payment->gross - $payment->fees === $current->requested_net
                    && ($payment->requested === null || $payment->requested === $current->requested_net)
                    && $refunded === $payment->gross) {
                    if (! $current->state instanceof Reversed) {
                        $current->state->transitionTo(Reversed::class);
                    }
                    PaymentException::where('deposit_id', $current->id)->whereIn('code', ['unrecognized_refund', 'reversal_evidence_required'])->update(['resolved_at' => $this->clock->now(), 'provider_open' => false]);
                    PaymentException::record('unrecognized_reversal', 'Payment reversed before customer value was recognized; no principal recovery is permitted. Any attributable fee needs separate verified cost evidence.', $this->clock->now(), $current->id, resourceType: 'refund', resourceId: $refund->id);
                } else {
                    PaymentException::record('unrecognized_refund', 'Refund preceded recognition without a verified whole-payment reversal; preserve evidence and investigate remaining value without guessing credit or recovery.', $this->clock->now(), $current->id, resourceType: 'refund', resourceId: $refund->id, providerOpen: true);
                }

                return;
            }
            if ($existing?->journal_id !== null) {
                return;
            }
            $previous = PaymentReversal::where('deposit_id', $current->id)->whereNotNull('journal_id');
            $principalRemaining = $current->requested_net - (clone $previous)->sum('principal');
            $feesRemaining = $current->fee_amount - (clone $previous)->sum('fee');
            $principal = min($refund->amount, $principalRemaining);
            $fee = $refund->amount - $principal;
            if ($principal < 0 || $fee > $feesRemaining) {
                PaymentException::record('refund_cap_exceeded', 'Refund financial effect exceeds remaining attributable principal or fees.', $this->clock->now(), $current->id, resourceType: 'refund', resourceId: $refund->id);

                return;
            }
            $effect = $existing ?? new PaymentReversal;
            $effect->forceFill(['deposit_id' => $current->id, 'provider_refund_id' => $refund->id, 'provider_dispute_id' => $refund->disputeId,
                'fingerprint' => $fingerprint, 'amount' => $refund->amount, 'principal' => $principal, 'fee' => $fee,
                'evidence' => $refund->evidence, 'verified_at' => $this->clock->now()])->save();
            $this->ledger->recoverDeposit($effect);
            if ($principal === $principalRemaining && ! $current->state instanceof Reversed) {
                $current->state->transitionTo(Reversed::class);
            }
            PaymentException::where('deposit_id', $current->id)->where('resource_type', 'refund')->where('resource_id', $refund->id)->update(['resolved_at' => $this->clock->now(), 'provider_open' => false]);
            PaymentException::record('recovery_required', 'A verified deposit reversal was applied; review the customer recovery allocation.', $this->clock->now(), $current->id);
            $this->notices->record($current, 'reversal', 'refund:'.$refund->id, $effect->refresh());
        });
    }

    public function dispute(string $id): void
    {
        $lock = Cache::lock('payment-dispute:'.$id, config('payments.claim_seconds'));
        if (! $lock->get()) {
            return;
        }
        try {
            $this->reconcileDispute($id);
        } finally {
            $lock->release();
        }
    }

    private function reconcileDispute(string $id): void
    {
        $data = $this->gateway->dispute($id);
        $transaction = $data['transaction'] ?? null;
        $transactionId = VerifiedPayment::id(is_array($transaction) ? ($transaction['id'] ?? null) : $transaction);
        $deposit = Deposit::where('provider_id', $transactionId)->first();
        if (($data['domain'] ?? null) !== 'test' || $deposit === null) {
            PaymentException::record('dispute_evidence_required', 'Dispute has no verified local test-mode association.', $this->clock->now(), resourceType: 'dispute', resourceId: $id, providerOpen: true);

            return;
        }
        $case = PaymentException::record('provider_dispute', 'A provider dispute requires prompt staff attention; no financial recovery is inferred from dispute status alone.', $this->clock->now(), $deposit->id, resourceType: 'dispute', resourceId: $id, providerOpen: true);
        $dueAt = $data['dueAt'] ?? $data['due_at'] ?? null;
        if (is_string($dueAt)) {
            $case->forceFill(['response_due_at' => CarbonImmutable::parse($dueAt)->utc()])->save();
        }
        if (! $this->scanDisputeRefunds($case, $transactionId)) {
            return;
        }
        if (($data['status'] ?? null) === 'resolved' && ($data['resolution'] ?? null) === 'declined') {
            $case->forceFill(['provider_open' => false, 'resolved_at' => $this->clock->now()])->save();
        } elseif (($data['status'] ?? null) === 'resolved' && ($data['resolution'] ?? null) === 'merchant-accepted') {
            $expectedRefund = new Money($data['refund_amount'] ?? null);
            $booked = PaymentReversal::where('deposit_id', $deposit->id)->where('provider_dispute_id', $id)->whereNotNull('journal_id')->sum('amount');
            if ($expectedRefund->kobo > 0 && $booked === $expectedRefund->kobo) {
                $case->forceFill(['provider_open' => false, 'resolved_at' => $this->clock->now()])->save();
            }
        }
    }

    private function scanDisputeRefunds(PaymentException $case, string $transactionId): bool
    {
        if ($case->refund_scan_to === null) {
            $case->forceFill(['refund_scan_to' => $this->clock->now(), 'next_refund_page' => 1, 'refund_page_offset' => 0])->save();
        }
        $deadline = microtime(true) + config('payments.scan_seconds');
        for ($page = 0; $page < config('payments.pages_per_run') && microtime(true) < $deadline; $page++) {
            $refunds = $this->gateway->list('refund', ['transaction' => $transactionId, 'to' => $case->refund_scan_to->toIso8601String(),
                'perPage' => config('payments.batch_size'), 'page' => $case->next_refund_page]);
            foreach (array_slice($refunds, $case->refund_page_offset) as $refund) {
                if (microtime(true) >= $deadline) {
                    return false;
                }
                $this->refund(VerifiedPayment::id($refund['id'] ?? null));
                $case->forceFill(['refund_page_offset' => $case->refund_page_offset + 1])->save();
            }
            if (count($refunds) < config('payments.batch_size')) {
                $case->forceFill(['refund_scan_to' => null, 'next_refund_page' => 1, 'refund_page_offset' => 0])->save();

                return true;
            }
            $case->forceFill(['next_refund_page' => $case->next_refund_page + 1, 'refund_page_offset' => 0])->save();
        }

        return false;
    }
}
