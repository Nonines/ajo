<?php

namespace App\Support;

use App\Contracts\Clock;
use App\Contracts\PaymentGateway;
use App\Models\Deposit;
use App\Models\LedgerAccount;
use App\Models\PaymentException;
use App\Models\PaymentReversal;
use App\Models\User;
use App\Support\DepositStates\Confirmed;
use App\Support\DepositStates\Failed;
use App\Support\DepositStates\PendingConfirmation;
use App\Support\DepositStates\Reversed;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Str;
use Throwable;

class Deposits
{
    public function __construct(private FinancialTransaction $transactions, private PaymentGateway $gateway, private Ledger $ledger, private Wallets $wallets, private Clock $clock, private PaymentNotices $notices) {}

    public function initialize(User $user, Money $net, string $requestKey): Deposit
    {
        if ($net->kobo < config('payments.minimum_net') || $net->kobo > config('payments.maximum_net')) {
            throw new DomainException('Net deposits must be between NGN 1,000 and NGN 100,000,000.');
        }
        [$deposit, $created] = $this->transactions->run([$user->id], function () use ($user, $net, $requestKey): array {
            $customer = User::findOrFail($user->id);
            if (! $customer->is_active || ! $customer->hasVerifiedEmail()) {
                throw new DomainException('Only active verified customers can initialize checkout.');
            }
            $existing = Deposit::where('user_id', $customer->id)->where('request_key', $requestKey)->first();
            if ($existing !== null) {
                if ($existing->requested_net !== $net->kobo) {
                    throw new DomainException('Checkout request key was already used for a different amount.');
                }

                return [$existing, false];
            }
            $this->wallets->provision($customer);
            $deposit = new Deposit;
            $deposit->forceFill(['user_id' => $customer->id, 'request_key' => $requestKey, 'reference' => 'ajo-'.Str::uuid(),
                'payer_email' => $customer->email, 'requested_net' => $net->kobo,
                'created_at' => $this->clock->now(), 'next_verify_at' => $this->clock->now()->addMinutes(config('payments.initial_delay_minutes'))])->save();

            return [$deposit, true];
        });
        if (! $created) {
            return $deposit;
        }
        try {
            $accessCode = $this->gateway->initialize($deposit);
            $this->transactions->run([$user->id], function () use ($deposit, $accessCode): void {
                $current = Deposit::findOrFail($deposit->id);
                $current->forceFill(['access_code' => $accessCode])->save();
                if ((string) $current->state === 'initiated') {
                    $current->state->transitionTo(PendingConfirmation::class);
                }
            });
        } catch (Throwable $exception) {
            $this->transactions->run([$user->id], function () use ($deposit, $exception): void {
                $current = Deposit::findOrFail($deposit->id);
                if ((string) $current->state === 'initiated') {
                    $current->state->transitionTo(Failed::class);
                }
                $current->forceFill(['provider_status' => 'initialization_uncertain'])->save();
                if (! $exception instanceof GatewayFailure) {
                    PaymentException::record('initialization_mismatch', 'Provider checkout details require investigation.', $this->clock->now(), $current->id);
                }
            });
        }

        return $deposit->refresh();
    }

    public function verify(Deposit $deposit): void
    {
        try {
            $payment = $this->gateway->verify($deposit->reference);
        } catch (GatewayFailure $exception) {
            $this->defer($deposit, $exception->retryAfter);

            return;
        } catch (Throwable) {
            PaymentException::record('verification_mismatch', 'Provider transaction evidence is invalid or incomplete.', $this->clock->now(), $deposit->id);
            $this->defer($deposit, 300);

            return;
        }
        $this->recognize($deposit, $payment);
    }

    public function recognize(Deposit $deposit, VerifiedPayment $payment): void
    {
        $this->transactions->run([$deposit->user_id], function () use ($deposit, $payment): void {
            $current = Deposit::findOrFail($deposit->id);
            if ($payment->reference !== $current->reference || strcasecmp($payment->email, $current->payer_email) !== 0 || $payment->domain !== 'test' || $payment->currency !== 'NGN'
                || ($current->provider_id !== null && $current->provider_id !== $payment->id)
                || Deposit::where('origin', $current->origin)->where('provider_id', $payment->id)->where('id', '!=', $current->id)->exists()) {
                PaymentException::record('verification_mismatch', 'Provider reference, customer, environment, currency, or identity does not match.', $this->clock->now(), $current->id);

                return;
            }
            $current->forceFill(['provider_id' => $payment->id, 'provider_status' => $payment->status, 'last_verified_at' => $this->clock->now()])->save();
            if ($payment->status !== 'success') {
                if ($current->recognized_at !== null) {
                    if ($payment->status === 'reversed') {
                        PaymentException::record('reversal_evidence_required', 'A reversal needs verified refund or dispute financial evidence.', $this->clock->now(), $current->id);
                    } elseif (! in_array($payment->status, ['pending', 'ongoing', 'processing', 'queued'], true)) {
                        PaymentException::record('conflicting_status', 'Provider observation conflicts with recognized customer value.', $this->clock->now(), $current->id);
                    }
                } elseif ($payment->status === 'reversed' && ! $current->state instanceof Reversed) {
                    PaymentException::record('reversal_evidence_required', 'A reversed status does not establish the amount of an unrecognized reversal.', $this->clock->now(), $current->id);
                } elseif (in_array($payment->status, ['failed', 'abandoned'], true) && in_array((string) $current->state, ['initiated', 'pending_confirmation'], true)) {
                    $current->state->transitionTo(Failed::class);
                    $this->notices->record($current, 'failed', 'deposit:'.$current->id.':failed');
                }
                $current->forceFill(['next_verify_at' => $this->nextVerification($current, in_array($payment->status, ['failed', 'abandoned'], true))])->save();

                return;
            }
            if ($payment->gross <= 0 || $payment->fees < 0 || $payment->gross - $payment->fees !== $current->requested_net
                || ($payment->requested !== null && $payment->requested !== $current->requested_net) || $payment->paidAt === null) {
                PaymentException::record('amount_mismatch', 'Verified gross charge, fee, or requested net value does not match.', $this->clock->now(), $current->id);

                return;
            }
            if ($current->recognized_at !== null) {
                if ($current->gross_amount !== $payment->gross || $current->fee_amount !== $payment->fees) {
                    PaymentException::record('amount_mismatch', 'Provider changed amounts after recognition.', $this->clock->now(), $current->id);
                }

                return;
            }
            if ($current->state instanceof Reversed) {
                PaymentException::record('conflicting_status', 'A success conflicts with a previously verified unrecognized reversal.', $this->clock->now(), $current->id);

                return;
            }
            if (PaymentReversal::where('deposit_id', $current->id)->whereNull('journal_id')->exists()) {
                PaymentException::record('unrecognized_refund', 'Unallocated refund evidence requires investigation before any customer value can be recognized.', $this->clock->now(), $current->id);

                return;
            }
            $wallet = $this->wallets->provision(User::findOrFail($current->user_id));
            $receivable = LedgerAccount::where('code', 'customer:'.$current->user_id.':reversal_receivable')->sole();
            $debt = $this->ledger->balance($receivable)->kobo;
            if ($debt < 0) {
                throw new DomainException('Recovery receivable cannot be negative.');
            }
            $repayment = min($debt, $current->requested_net);
            $credit = $current->requested_net - $repayment;
            $date = $payment->paidAt->setTimezone('Africa/Lagos')->toDateString();
            $entries = [$this->entry('house:cash_at_gateway', $payment->gross)];
            if ($credit > 0) {
                $entries[] = ['account_id' => $wallet->id, 'amount' => new Money(-$credit)];
            }
            if ($repayment > 0) {
                $entries[] = ['account_id' => $receivable->id, 'amount' => new Money(-$repayment)];
            }
            if ($payment->fees > 0) {
                $entries[] = $this->entry('house:fee_recovery_income', -$payment->fees);
            }
            $journal = $this->ledger->post('deposit:'.$current->id.':recognition', $entries, $date, 'Deposit confirmed', $current->user_id);
            $feeJournal = $payment->fees > 0 ? $this->ledger->post('deposit:'.$current->id.':fees', [
                $this->entry('house:gateway_fees', $payment->fees), $this->entry('house:cash_at_gateway', -$payment->fees),
            ], $date, 'Deposit processing fee', $current->user_id) : null;
            $current->forceFill(['gross_amount' => $payment->gross, 'fee_amount' => $payment->fees, 'wallet_credit' => $credit,
                'debt_repayment' => $repayment, 'recognition_journal_id' => $journal->id, 'fee_journal_id' => $feeJournal?->id,
                'recognized_at' => $this->clock->now(), 'next_verify_at' => null])->save();
            $current->state->transitionTo(Confirmed::class);
            PaymentException::where('deposit_id', $current->id)->whereIn('code', ['unresolved_payment', 'initialization_mismatch'])->update(['resolved_at' => $this->clock->now()]);
            $this->notices->record($current, 'confirmed', 'deposit:'.$current->id.':confirmed');
        });
    }

    private function defer(Deposit $deposit, int $seconds): void
    {
        $this->transactions->run([$deposit->user_id], function () use ($deposit, $seconds): void {
            $current = Deposit::findOrFail($deposit->id);
            $next = $this->nextVerification($current, false);
            $providerRetry = $this->clock->now()->addSeconds($seconds);
            $current->forceFill(['next_verify_at' => $next->greaterThan($providerRetry) ? $next : $providerRetry])->save();
        });
    }

    private function nextVerification(Deposit $deposit, bool $failed): CarbonImmutable
    {
        $aged = $deposit->created_at->addHours(config('payments.escalation_hours'))->lte($this->clock->now());
        $minutes = ! $aged ? config('payments.verification_minutes') : ($failed ? config('payments.failed_verification_minutes') : config('payments.aged_verification_minutes'));

        return $this->clock->now()->addMinutes($minutes);
    }

    /** @return array{account_id: int, amount: Money} */
    private function entry(string $code, int $amount): array
    {
        return ['account_id' => LedgerAccount::where('code', $code)->sole()->id, 'amount' => new Money($amount)];
    }
}
