<?php

namespace App\Support\Payments;

use App\Support\Money\Money;
use DomainException;

/**
 * Carries parsed refund evidence, including whether the actual deduction amount is known.
 */
final readonly class VerifiedRefund
{
    /** @param array<string, mixed> $evidence */
    public function __construct(public string $id, public string $transactionId, public ?string $disputeId, public string $domain, public string $currency, public string $status, public int $amount, public ?int $deducted, public bool $fullyDeducted, public array $evidence) {}

    /**
     * Parses refund identity and amounts while retaining the original evidence for recovery checks.
     *
     * @param  array<string, mixed>  $data
     */
    public static function from(array $data): self
    {
        $transaction = $data['transaction'] ?? null;

        if (is_array($transaction)) {
            $transaction = $transaction['id'] ?? null;
        }

        foreach (['domain', 'currency', 'status'] as $field) {
            if (! is_string($data[$field] ?? null)) {
                throw new DomainException('Provider refund evidence is incomplete.');
            }
        }

        return new self(VerifiedPayment::id($data['id'] ?? null), VerifiedPayment::id($transaction), isset($data['dispute']) ? VerifiedPayment::id($data['dispute']) : null,
            $data['domain'], $data['currency'], $data['status'], (new Money($data['amount'] ?? null))->kobo,
            isset($data['deducted_amount']) ? (new Money($data['deducted_amount']))->kobo : null,
            in_array($data['fully_deducted'] ?? null, [true, 1], true), $data);
    }
}
