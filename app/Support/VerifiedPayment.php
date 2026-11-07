<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DomainException;

final readonly class VerifiedPayment
{
    public function __construct(
        public string $id, public string $reference, public string $email,
        public string $domain, public string $currency, public string $status,
        public int $gross, public int $fees, public ?int $requested,
        public ?CarbonImmutable $paidAt,
    ) {}

    /** @param array<string, mixed> $data */
    public static function from(array $data): self
    {
        $customer = $data['customer'] ?? null;
        if (! is_array($customer) || ! is_string($customer['email'] ?? null)) {
            throw new DomainException('Provider customer evidence is missing.');
        }
        foreach (['reference', 'domain', 'currency', 'status'] as $field) {
            if (! is_string($data[$field] ?? null)) {
                throw new DomainException('Provider transaction evidence is incomplete.');
            }
        }
        $paidAt = $data['paid_at'] ?? $data['paidAt'] ?? null;
        if ($data['status'] === 'success' && (! is_string($paidAt) || $paidAt === '')) {
            throw new DomainException('Provider payment time is missing.');
        }
        if (in_array($data['status'], ['success', 'reversed'], true) && ! isset($data['fees'])) {
            throw new DomainException('Provider fee evidence is missing.');
        }

        return new self(
            self::id($data['id'] ?? null), $data['reference'], $customer['email'], $data['domain'], $data['currency'], $data['status'],
            (new Money($data['amount'] ?? null))->kobo, (new Money($data['fees'] ?? 0))->kobo,
            isset($data['requested_amount']) ? (new Money($data['requested_amount']))->kobo : null,
            is_string($paidAt) && $paidAt !== '' ? CarbonImmutable::parse($paidAt)->utc() : null,
        );
    }

    public static function id(mixed $value): string
    {
        if ((! is_string($value) && ! is_int($value)) || ! preg_match('/^[0-9]{1,64}$/D', (string) $value)) {
            throw new DomainException('Provider resource identity is invalid.');
        }

        return (string) $value;
    }
}
