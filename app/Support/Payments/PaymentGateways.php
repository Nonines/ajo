<?php

namespace App\Support\Payments;

use App\Models\Deposit;
use App\Models\WithdrawalRequest;

/**
 * Chooses real or simulated verification from persisted origin rather than incoming event claims.
 */
class PaymentGateways extends PaystackGateway
{
    /**
     * Selects checkout behavior from the deposit's persisted origin.
     */
    public function initialize(Deposit $deposit): string
    {
        return $deposit->origin === 'simulated' ? 'simulated-checkout' : parent::initialize($deposit);
    }

    /**
     * Chooses payment verification from local provenance rather than incoming claims.
     */
    public function verify(string $reference): VerifiedPayment
    {
        $deposit = Deposit::where('reference', $reference)->first();

        return $deposit?->origin === 'simulated' ? app(SimulatedGateway::class)->verify($reference) : parent::verify($reference);
    }

    /**
     * Submits through the adapter selected by the saved reservation origin.
     */
    public function transfer(WithdrawalRequest $withdrawal): array
    {
        return $withdrawal->origin === 'simulated' ? app(SimulatedGateway::class)->transfer($withdrawal) : parent::transfer($withdrawal);
    }

    /**
     * Chooses payout verification from the local reservation's persisted origin.
     */
    public function verifyTransfer(string $reference): VerifiedTransfer
    {
        $withdrawal = WithdrawalRequest::where('reference', $reference)->first();

        return $withdrawal?->origin === 'simulated' ? app(SimulatedGateway::class)->verifyTransfer($reference) : parent::verifyTransfer($reference);
    }

    /**
     * Uses the explicit origin to select real or simulated refund evidence.
     */
    public function refund(string $id, string $origin = 'real'): VerifiedRefund
    {
        return $origin === 'simulated' ? app(SimulatedGateway::class)->refund($id) : parent::refund($id, $origin);
    }
}
