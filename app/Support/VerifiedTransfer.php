<?php

namespace App\Support;

use DomainException;

final readonly class VerifiedTransfer
{
    public function __construct(
        public string $id, public string $reference, public string $domain,
        public string $currency, public string $status, public int $amount,
        public string $recipientCode, public ?int $fee,
    ) {}

    /** @param array<string, mixed> $data */
    public static function from(array $data): self
    {
        foreach (['reference', 'domain', 'currency', 'status'] as $field) {
            if (! is_string($data[$field] ?? null) || $data[$field] === '') {
                throw new DomainException('Incomplete transfer evidence.');
            }
        }
        $recipient = $data['recipient'] ?? null;
        if (! is_array($recipient) || ! is_string($recipient['recipient_code'] ?? null)) {
            throw new DomainException('Transfer recipient evidence is missing.');
        }
        $amount = new Money($data['amount'] ?? null);
        $fee = isset($data['fee_charged']) ? (new Money($data['fee_charged']))->kobo : null;
        if ($amount->kobo <= 0 || ($fee !== null && $fee < 0)) {
            throw new DomainException('Transfer financial evidence is invalid.');
        }

        return new self(VerifiedPayment::id($data['id'] ?? null), $data['reference'], $data['domain'],
            $data['currency'], $data['status'], $amount->kobo, $recipient['recipient_code'], $fee);
    }
}
