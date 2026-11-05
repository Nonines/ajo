<?php

namespace App\Support;

use App\Contracts\Clock;
use App\Contracts\PaymentGateway;
use App\Models\Deposit;
use DomainException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

class PaystackGateway implements PaymentGateway
{
    public function __construct(private Clock $clock) {}

    public function initialize(Deposit $deposit): string
    {
        $data = $this->request('post', 'transaction/initialize', [
            'email' => $deposit->payer_email, 'amount' => (string) $deposit->requested_net, 'currency' => 'NGN',
            'reference' => $deposit->reference, 'callback_url' => route('deposits.callback', ['reference' => $deposit->reference]),
        ]);
        if (($data['reference'] ?? null) !== $deposit->reference || ! is_string($data['access_code'] ?? null) || $data['access_code'] === '') {
            throw new DomainException('Provider initialization did not match the saved deposit.');
        }

        return $data['access_code'];
    }

    public function verify(string $reference): VerifiedPayment
    {
        if (! preg_match('/^[A-Za-z0-9.=-]{1,255}$/D', $reference)) {
            throw new DomainException('Invalid payment reference.');
        }

        return VerifiedPayment::from($this->request('get', 'transaction/verify/'.rawurlencode($reference)));
    }

    public function refund(string $id): VerifiedRefund
    {
        $refund = VerifiedRefund::from($this->request('get', 'refund/'.VerifiedPayment::id($id)));
        if ($refund->id !== $id) {
            throw new DomainException('Provider refund identity mismatch.');
        }

        return $refund;
    }

    /** @return array<string, mixed> */
    public function dispute(string $id): array
    {
        $data = $this->request('get', 'dispute/'.VerifiedPayment::id($id));
        if (VerifiedPayment::id($data['id'] ?? null) !== $id) {
            throw new DomainException('Provider dispute identity mismatch.');
        }

        return $data;
    }

    /** @param array<string, int|string> $query
     * @return list<array<string, mixed>>
     */
    public function list(string $resource, array $query): array
    {
        if (! in_array($resource, ['transaction', 'refund', 'dispute'], true)) {
            throw new DomainException('Unsupported payment resource.');
        }
        $data = $this->request('get', $resource, $query);
        if (! array_is_list($data)) {
            throw new DomainException('Provider listing is malformed.');
        }
        foreach ($data as $item) {
            if (! is_array($item)) {
                throw new DomainException('Provider listing item is malformed.');
            }
        }

        return $data;
    }

    /** @param array<string, int|string> $parameters
     * @return array<array-key, mixed>
     */
    private function request(string $method, string $path, array $parameters = []): array
    {
        $secret = config('payments.secret_key');
        if (! is_string($secret) || ! str_starts_with($secret, 'sk_test_')) {
            throw new GatewayFailure('A Paystack test secret is required.');
        }
        $endpoint = explode('/', $path)[0];
        $key = 'payments:provider:'.$endpoint;
        $blockedUntil = (int) Cache::get($key.':blocked', 0);
        if ($blockedUntil > $this->clock->now()->getTimestamp()) {
            throw new GatewayFailure('Provider requests are temporarily paused.', $blockedUntil - $this->clock->now()->getTimestamp());
        }
        if (RateLimiter::tooManyAttempts($key, config('payments.provider_budget'))) {
            throw new GatewayFailure('Provider request budget is exhausted.', max(1, RateLimiter::availableIn($key)));
        }
        RateLimiter::hit($key, 60);
        try {
            $request = Http::baseUrl('https://api.paystack.co')->withToken($secret)->acceptJson()
                ->connectTimeout(config('payments.http_connect_timeout'))->timeout(config('payments.http_timeout'));
            $response = $method === 'post' ? $request->post($path, $parameters) : $request->get($path, $parameters);
        } catch (ConnectionException) {
            throw new GatewayFailure('Provider connection did not establish a verified outcome.');
        }
        if ($response->status() === 429) {
            $reset = (int) $response->header('x-ratelimit-reset');
            $wait = max(1, $reset > $this->clock->now()->getTimestamp() ? $reset - $this->clock->now()->getTimestamp() : $reset);
            Cache::put($key.':blocked', $this->clock->now()->getTimestamp() + $wait, $wait);
            throw new GatewayFailure('Provider rate limit reached.', $wait);
        }
        if (! $response->successful()) {
            throw new GatewayFailure('Provider request did not return a verified result.');
        }
        $payload = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        if (! is_array($payload) || ($payload['status'] ?? null) !== true || ! is_array($payload['data'] ?? null)) {
            throw new DomainException('Provider response is malformed.');
        }

        return $payload['data'];
    }
}
