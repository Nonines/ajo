<?php

namespace App\Support;

use App\Contracts\Clock;
use App\Contracts\PaymentGateway;
use App\Models\Deposit;
use App\Models\WithdrawalRequest;
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

    public function refund(string $id, string $origin = 'real'): VerifiedRefund
    {
        if ($origin !== 'real') {
            throw new DomainException('The real gateway cannot verify a simulated refund.');
        }
        $refund = VerifiedRefund::from($this->request('get', 'refund/'.VerifiedPayment::id($id)));
        if ($refund->id !== $id) {
            throw new DomainException('Provider refund identity mismatch.');
        }

        return $refund;
    }

    public function resolveAccount(string $accountNumber, string $bankCode): array
    {
        return $this->request('get', 'bank/resolve', ['account_number' => $accountNumber, 'bank_code' => $bankCode]);
    }

    public function banks(): array
    {
        return Cache::remember('payments:banks:NGN', 3600, function (): array {
            $banks = [];
            $cursor = null;
            $seen = [];
            for ($page = 0; $page < 10; $page++) {
                $query = ['country' => 'nigeria', 'currency' => 'NGN', 'perPage' => 100, 'use_cursor' => 'true'];
                if ($cursor !== null) {
                    $query['next'] = $cursor;
                }
                $response = $this->request('get', 'bank', $query, true);
                foreach ($response['data'] as $bank) {
                    if (! is_array($bank) || ! is_string($bank['code'] ?? null) || ! is_string($bank['name'] ?? null)) {
                        throw new DomainException('Bank list is malformed.');
                    }
                    if (($bank['active'] ?? false) === true && ($bank['currency'] ?? null) === 'NGN' && ($bank['type'] ?? null) === 'nuban') {
                        $banks[$bank['code']] = ['code' => $bank['code'], 'name' => $bank['name']];
                    }
                }
                $cursor = $response['meta']['next'] ?? null;
                if ($cursor === null) {
                    usort($banks, static fn (array $left, array $right): int => strcmp($left['name'], $right['name']));

                    return $banks;
                }
                if (! is_string($cursor) || isset($seen[$cursor])) {
                    throw new DomainException('Bank list cursor is invalid.');
                }
                $seen[$cursor] = true;
            }
            throw new GatewayFailure('Bank list could not be completed.');
        });
    }

    public function createRecipient(string $accountNumber, string $bankCode, string $name): array
    {
        return $this->request('post', 'transferrecipient', ['type' => 'nuban', 'name' => $name,
            'account_number' => $accountNumber, 'bank_code' => $bankCode, 'currency' => 'NGN']);
    }

    public function transfer(WithdrawalRequest $withdrawal): array
    {
        return $this->request('post', 'transfer', ['source' => 'balance', 'currency' => 'NGN',
            'reference' => $withdrawal->reference, 'amount' => (string) $withdrawal->principal,
            'recipient' => $withdrawal->destination['recipient_code'], 'reason' => 'Ajo wallet withdrawal']);
    }

    public function verifyTransfer(string $reference): VerifiedTransfer
    {
        if (! preg_match('/^[a-z0-9_-]{16,50}$/D', $reference)) {
            throw new DomainException('Invalid transfer reference.');
        }
        $verified = $this->request('get', 'transfer/verify/'.rawurlencode($reference));
        $id = VerifiedPayment::id($verified['id'] ?? null);
        $fetched = $this->request('get', 'transfer/'.$id);
        foreach (['id', 'reference', 'amount', 'currency', 'domain', 'status'] as $field) {
            if (! isset($verified[$field], $fetched[$field]) || (string) $verified[$field] !== (string) $fetched[$field]) {
                throw new DomainException('Provider transfer verification and fee evidence disagree.');
            }
        }

        return VerifiedTransfer::from($fetched);
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
    private function request(string $method, string $path, array $parameters = [], bool $includeMetadata = false): array
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

        return $includeMetadata ? $payload : $payload['data'];
    }
}
