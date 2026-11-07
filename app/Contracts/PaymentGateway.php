<?php

namespace App\Contracts;

use App\Models\Deposit;
use App\Models\WithdrawalRequest;
use App\Support\VerifiedPayment;
use App\Support\VerifiedRefund;
use App\Support\VerifiedTransfer;

interface PaymentGateway
{
    public function initialize(Deposit $deposit): string;

    public function verify(string $reference): VerifiedPayment;

    public function refund(string $id, string $origin = 'real'): VerifiedRefund;

    /** @return array<string, mixed> */
    public function resolveAccount(string $accountNumber, string $bankCode): array;

    /** @return list<array{code: string, name: string}> */
    public function banks(): array;

    /** @return array<string, mixed> */
    public function createRecipient(string $accountNumber, string $bankCode, string $name): array;

    /** @return array<string, mixed> */
    public function transfer(WithdrawalRequest $withdrawal): array;

    public function verifyTransfer(string $reference): VerifiedTransfer;

    /** @return array<string, mixed> */
    public function dispute(string $id): array;

    /** @param array<string, int|string> $query
     * @return list<array<string, mixed>>
     */
    public function list(string $resource, array $query): array;
}
