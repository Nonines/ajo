<?php

namespace App\Support;

use App\Contracts\Clock;
use App\Jobs\ProcessPaymentWebhook;
use App\Models\Deposit;
use App\Models\PaymentException;
use App\Models\PaymentSimulation;
use App\Models\WebhookEvent;
use App\Models\WithdrawalRequest;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Durably receives authenticated observations and routes them to independent financial verification.
 */
class PaymentInbox
{
    public function __construct(private Clock $clock, private Deposits $deposits, private DepositRecovery $recovery) {}

    /**
     * Authenticates the raw webhook body using the configured signature mode before persisting it.
     */
    public function receive(string $raw, ?string $signature): WebhookEvent
    {
        $mode = config('payments.webhook_mode');
        $secret = $mode === 'timestamped' ? config('payments.webhook_signing_secret') : config('payments.secret_key');
        if (! is_string($secret) || ($mode === 'legacy' ? ! str_starts_with($secret, 'sk_test_') : ! str_starts_with($secret, 'whsec_'))) {
            throw new HttpException(503, 'Webhook verification is not configured.');
        }
        $valid = false;
        if ($mode === 'legacy' && is_string($signature) && preg_match('/^[a-f0-9]{128}$/D', $signature)) {
            $valid = hash_equals(hash_hmac('sha512', $raw, $secret), $signature);
        } elseif ($mode === 'timestamped' && is_string($signature)) {
            $timestamp = null;
            $signatures = [];
            foreach (explode(',', $signature) as $part) {
                [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
                if ($key === 't') {
                    if ($timestamp !== null || ! preg_match('/^[0-9]{1,12}$/D', $value)) {
                        throw new HttpException(401, 'Invalid webhook signature.');
                    }
                    $timestamp = (int) $value;
                } elseif ($key === 'v1') {
                    $signatures[] = explode(':', $value, 2)[1] ?? '';
                }
            }
            if ($timestamp !== null && abs($this->clock->now()->getTimestamp() - $timestamp) <= 300) {
                $expected = hash_hmac('sha512', $timestamp.'.'.$raw, $secret);
                foreach ($signatures as $candidate) {
                    if (preg_match('/^[a-f0-9]{128}$/D', $candidate) && hash_equals($expected, $candidate)) {
                        $valid = true;
                    }
                }
            }
        }
        if (! $valid) {
            throw new HttpException(401, 'Invalid webhook signature.');
        }

        return $this->persist($raw);
    }

    /**
     * Accepts separately signed simulated events only in explicitly enabled local or test environments.
     */
    public function receiveSimulated(string $raw, string $signature): WebhookEvent
    {
        $secret = config('withdrawals.simulation_signing_key');
        if (! app()->environment(['local', 'testing']) || config('withdrawals.simulation_enabled') !== true
            || ! is_string($secret) || strlen($secret) < 32 || ! hash_equals(hash_hmac('sha512', $raw, $secret), $signature)) {
            throw new HttpException(401, 'Invalid simulated webhook signature or environment.');
        }

        return $this->persist($raw, 'simulated');
    }

    /**
     * Persists provider-list observations for the same verification path used by webhook receipts.
     *
     * @param  array<string, mixed>  $data
     */
    public function observe(string $resource, array $data): WebhookEvent
    {
        if (! in_array($resource, ['transaction', 'refund', 'dispute', 'transfer'], true)) {
            throw new DomainException('Unsupported provider observation.');
        }

        return $this->persist(json_encode(['event' => 'verified-list.'.$resource, 'data' => $data], JSON_THROW_ON_ERROR));
    }

    /**
     * Deduplicates by origin and raw payload, retaining the receipt even if queue dispatch fails.
     */
    private function persist(string $raw, string $origin = 'real'): WebhookEvent
    {
        $event = DB::transaction(function () use ($raw, $origin): WebhookEvent {
            $fingerprint = hash('sha256', $origin."\0".$raw);
            $existing = WebhookEvent::where('fingerprint', $fingerprint)->first();
            if ($existing !== null) {
                return $existing;
            }
            $event = new WebhookEvent;
            $event->forceFill(['fingerprint' => $fingerprint, 'payload' => $raw, 'origin' => $origin, 'received_at' => $this->clock->now(), 'next_attempt_at' => $this->clock->now()])->save();

            return $event;
        });
        try {
            ProcessPaymentWebhook::dispatch($event->id);
        } catch (Throwable) {
            // Acknowledgment depends on durable receipt, not Redis availability.
        }

        return $event;
    }

    /**
     * Claims and routes an observation to verification; receipt completion can precede financial resolution.
     */
    public function process(int $id): void
    {
        $token = (string) Str::uuid();
        $claimed = DB::transaction(function () use ($id, $token): ?WebhookEvent {
            $event = WebhookEvent::findOrFail($id);
            if ($event->processed_at !== null || ($event->claimed_at !== null && $event->claimed_at->addSeconds(config('payments.claim_seconds'))->gt($this->clock->now()))) {
                return null;
            }
            $event->forceFill(['claim_token' => $token, 'claimed_at' => $this->clock->now(), 'attempts' => $event->attempts + 1])->save();

            return $event;
        });
        if ($claimed === null) {
            return;
        }
        try {
            $payload = json_decode($claimed->payload, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            if (! is_array($payload) || ! is_string($payload['event'] ?? null) || ! is_array($payload['data'] ?? null)) {
                throw new DomainException('Webhook payload is malformed.');
            }
            $type = $payload['event'];
            $data = $payload['data'];
            if (in_array($type, ['charge.success', 'charge.failed', 'verified-list.transaction'], true)) {
                $reference = $data['reference'] ?? null;
                $deposit = is_string($reference) ? Deposit::where('reference', $reference)->first() : null;
                if ($deposit === null) {
                    PaymentException::record('orphan_payment', 'Authenticated payment observation has no matching local checkout.', $this->clock->now(), eventId: $id);
                } else {
                    if ($deposit->origin !== $claimed->origin) {
                        throw new DomainException('Deposit adapter provenance does not match the authenticated inbox.');
                    }
                    $this->deposits->verify($deposit);
                }
            } elseif (str_starts_with($type, 'refund.') || $type === 'verified-list.refund') {
                $refundId = VerifiedPayment::id($data['id'] ?? null);
                $simulated = PaymentSimulation::where('refund_id', $refundId)->exists();
                if ($claimed->origin === 'simulated' && ! $simulated) {
                    throw new DomainException('Refund adapter provenance does not match the authenticated inbox.');
                }
                $this->recovery->refund($refundId, $claimed->origin);
            } elseif (str_starts_with($type, 'charge.dispute.') || $type === 'verified-list.dispute') {
                if ($claimed->origin !== 'real') {
                    throw new DomainException('Simulated disputes are unsupported.');
                }
                $this->recovery->dispute(VerifiedPayment::id($data['id'] ?? null));
            } elseif (str_starts_with($type, 'transfer.') || $type === 'verified-list.transfer') {
                $reference = $data['reference'] ?? null;
                $withdrawal = is_string($reference) ? WithdrawalRequest::where('reference', $reference)->first() : null;
                if ($withdrawal === null) {
                    PaymentException::record('orphan_payout', 'Transfer observation has no local reservation.', $this->clock->now(), eventId: $id);
                } elseif ($withdrawal->origin !== $claimed->origin) {
                    throw new DomainException('Payout adapter provenance does not match the authenticated inbox.');
                } else {
                    app(Withdrawals::class)->verify($withdrawal);
                }
            }
            WebhookEvent::whereKey($id)->where('claim_token', $token)->update(['processed_at' => $this->clock->now(), 'claim_token' => null, 'claimed_at' => null]);
        } catch (GatewayFailure $exception) {
            $this->retry($id, $token, $exception->retryAfter);
        } catch (Throwable) {
            PaymentException::record('inbox_evidence_required', 'Authenticated provider evidence could not be safely processed.', $this->clock->now(), eventId: $id);
            $this->retry($id, $token, 300);
        }
    }

    /**
     * Reschedules only the claim owned by this worker so stale workers cannot release newer claims.
     */
    private function retry(int $id, string $token, int $seconds): void
    {
        WebhookEvent::whereKey($id)->where('claim_token', $token)->update(['claim_token' => null, 'claimed_at' => null, 'next_attempt_at' => $this->clock->now()->addSeconds(max(60, $seconds))]);
    }
}
