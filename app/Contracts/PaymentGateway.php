<?php

namespace App\Contracts;

use App\Models\Deposit;
use App\Models\WithdrawalRequest;
use App\Support\VerifiedPayment;
use App\Support\VerifiedRefund;
use App\Support\VerifiedTransfer;

/**
 * Separates provider requests and parsed evidence from local financial recognition.
 */
interface PaymentGateway
{
    /**
     * Returns checkout access for a saved deposit without recognizing wallet value.
     */
    public function initialize(Deposit $deposit): string;

    /**
     * Fetches parsed payment evidence for local identity and amount checks.
     */
    public function verify(string $reference): VerifiedPayment;

    /**
     * Fetches refund evidence using the explicitly selected adapter origin.
     */
    public function refund(string $id, string $origin = 'real'): VerifiedRefund;

    /** @return array<string, mixed> */
    public function resolveAccount(string $accountNumber, string $bankCode): array;

    /** @return list<array{code: string, name: string}> */
    public function banks(): array;

    /** @return array<string, mixed> */
    public function createRecipient(string $accountNumber, string $bankCode, string $name): array;

    /**
     * Submits the reserved payout; the response alone does not establish its final outcome.
     *
     * @return array<string, mixed>
     */
    public function transfer(WithdrawalRequest $withdrawal): array;

    /**
     * Fetches payout outcome evidence, retaining an unknown incurred fee as null.
     */
    public function verifyTransfer(string $reference): VerifiedTransfer;

    /** @return array<string, mixed> */
    public function dispute(string $id): array;

    /** @param array<string, int|string> $query
     * @return list<array<string, mixed>>
     */
    public function list(string $resource, array $query): array;
}
