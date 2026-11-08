<?php

namespace App\Support;

use App\Models\Deposit;
use App\Models\PaymentSimulation;
use App\Models\WithdrawalRequest;
use DomainException;

/**
 * Parses local simulation evidence through the ordinary financial verification value objects.
 */
class SimulatedGateway
{
    public function verify(string $reference): VerifiedPayment
    {
        $simulation = $this->simulation($reference, Deposit::class);

        return VerifiedPayment::from($simulation->evidence);
    }

    /**
     * Exercises the server-approval checks for a simulated payout before returning its reference.
     *
     * @return array<string, mixed>
     */
    public function transfer(WithdrawalRequest $withdrawal): array
    {
        $this->simulation($withdrawal->reference, WithdrawalRequest::class);
        if (! app(Withdrawals::class)->providerApproval(['reference' => $withdrawal->reference,
            'amount' => (string) $withdrawal->principal, 'recipient' => $withdrawal->destination['recipient_code'],
            'currency' => 'NGN', 'source' => 'balance'], 'simulated')) {
            throw new DomainException('Simulated server approval was rejected.');
        }

        return ['reference' => $withdrawal->reference];
    }

    public function verifyTransfer(string $reference): VerifiedTransfer
    {
        return VerifiedTransfer::from($this->simulation($reference, WithdrawalRequest::class)->evidence);
    }

    public function refund(string $id): VerifiedRefund
    {
        $simulation = PaymentSimulation::where('refund_id', $id)->sole();
        $deposit = Deposit::findOrFail($simulation->subject_id);
        $this->simulation($deposit->reference, Deposit::class);
        $amount = $deposit->gross_amount;

        return VerifiedRefund::from(['id' => $id, 'transaction' => $deposit->provider_id, 'domain' => 'test', 'currency' => 'NGN',
            'amount' => $amount, 'deducted_amount' => $amount, 'fully_deducted' => true, 'status' => 'processed']);
    }

    /**
     * Requires an enabled environment and a subject whose persisted origin and reference match.
     */
    private function simulation(string $reference, string $class): PaymentSimulation
    {
        if (! app()->environment(['local', 'testing']) || config('withdrawals.simulation_enabled') !== true) {
            throw new DomainException('Simulated verification is disabled in this environment.');
        }
        $simulation = PaymentSimulation::where('reference', $reference)->where('subject_type', $class)->sole();
        $subject = $class::findOrFail($simulation->subject_id);
        if ($subject->origin !== 'simulated' || $subject->reference !== $reference) {
            throw new DomainException('Real transactions cannot use simulated verification.');
        }

        return $simulation;
    }
}
