<?php

namespace App\Support;

use App\Contracts\Clock;
use App\Jobs\ProcessPaymentWebhook;
use App\Models\Deposit;
use App\Models\PaymentException;
use App\Models\WebhookEvent;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class PaymentInbox
{
    public function __construct(private Clock $clock, private Deposits $deposits, private DepositRecovery $recovery) {}

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

    /** @param array<string, mixed> $data */
    public function observe(string $resource, array $data): WebhookEvent
    {
        if (! in_array($resource, ['transaction', 'refund', 'dispute'], true)) {
            throw new DomainException('Unsupported provider observation.');
        }

        return $this->persist(json_encode(['event' => 'verified-list.'.$resource, 'data' => $data], JSON_THROW_ON_ERROR));
    }

    private function persist(string $raw): WebhookEvent
    {
        $event = DB::transaction(function () use ($raw): WebhookEvent {
            $fingerprint = hash('sha256', $raw);
            $existing = WebhookEvent::where('fingerprint', $fingerprint)->first();
            if ($existing !== null) {
                return $existing;
            }
            $event = new WebhookEvent;
            $event->forceFill(['fingerprint' => $fingerprint, 'payload' => $raw, 'received_at' => $this->clock->now(), 'next_attempt_at' => $this->clock->now()])->save();

            return $event;
        });
        try {
            ProcessPaymentWebhook::dispatch($event->id);
        } catch (Throwable) {
            // Acknowledgment depends on durable receipt, not Redis availability.
        }

        return $event;
    }

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
                    $this->deposits->verify($deposit);
                }
            } elseif (str_starts_with($type, 'refund.') || $type === 'verified-list.refund') {
                $this->recovery->refund(VerifiedPayment::id($data['id'] ?? null));
            } elseif (str_starts_with($type, 'charge.dispute.') || $type === 'verified-list.dispute') {
                $this->recovery->dispute(VerifiedPayment::id($data['id'] ?? null));
            }
            WebhookEvent::whereKey($id)->where('claim_token', $token)->update(['processed_at' => $this->clock->now(), 'claim_token' => null, 'claimed_at' => null]);
        } catch (GatewayFailure $exception) {
            $this->retry($id, $token, $exception->retryAfter);
        } catch (Throwable) {
            PaymentException::record('inbox_evidence_required', 'Authenticated provider evidence could not be safely processed.', $this->clock->now(), eventId: $id);
            $this->retry($id, $token, 300);
        }
    }

    private function retry(int $id, string $token, int $seconds): void
    {
        WebhookEvent::whereKey($id)->where('claim_token', $token)->update(['claim_token' => null, 'claimed_at' => null, 'next_attempt_at' => $this->clock->now()->addSeconds(max(60, $seconds))]);
    }
}
