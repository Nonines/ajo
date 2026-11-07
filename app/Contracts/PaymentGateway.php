<?php

namespace App\Contracts;

use App\Models\Deposit;
use App\Support\VerifiedPayment;
use App\Support\VerifiedRefund;

interface PaymentGateway
{
    public function initialize(Deposit $deposit): string;

    public function verify(string $reference): VerifiedPayment;

    public function refund(string $id): VerifiedRefund;

    /** @return array<string, mixed> */
    public function dispute(string $id): array;

    /** @param array<string, int|string> $query
     * @return list<array<string, mixed>>
     */
    public function list(string $resource, array $query): array;
}
