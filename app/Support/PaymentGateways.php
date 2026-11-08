<?php

namespace App\Support;

use App\Models\Deposit;
use App\Models\WithdrawalRequest;

/**
 * Chooses real or simulated verification from persisted origin rather than incoming event claims.
 */
class PaymentGateways extends PaystackGateway
{
    public function initialize(Deposit $deposit): string
    {
        return $deposit->origin === 'simulated' ? 'simulated-checkout' : parent::initialize($deposit);
    }

    public function verify(string $reference): VerifiedPayment
    {
        $deposit = Deposit::where('reference', $reference)->first();

        return $deposit?->origin === 'simulated' ? app(SimulatedGateway::class)->verify($reference) : parent::verify($reference);
    }

    public function transfer(WithdrawalRequest $withdrawal): array
    {
        return $withdrawal->origin === 'simulated' ? app(SimulatedGateway::class)->transfer($withdrawal) : parent::transfer($withdrawal);
    }

    public function verifyTransfer(string $reference): VerifiedTransfer
    {
        $withdrawal = WithdrawalRequest::where('reference', $reference)->first();

        return $withdrawal?->origin === 'simulated' ? app(SimulatedGateway::class)->verifyTransfer($reference) : parent::verifyTransfer($reference);
    }

    public function refund(string $id, string $origin = 'real'): VerifiedRefund
    {
        return $origin === 'simulated' ? app(SimulatedGateway::class)->refund($id) : parent::refund($id, $origin);
    }
}
