<?php

namespace App\Support\Payments;

use App\Contracts\Clock;
use App\Models\Deposit;
use App\Models\PaymentSimulation;
use App\Models\SavedDestination;
use App\Models\Staff;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Support\Audit\AuditTrail;
use App\Support\Auth\Approvals;
use App\Support\Ledger\FinancialTransaction;
use App\Support\Ledger\Wallets;
use App\Support\Money\Money;
use App\Support\Payments\DepositStates\PendingConfirmation;
use App\Support\Withdrawals\Withdrawals;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;

/**
 * Creates local financial scenarios that exercise ordinary approval and verification paths.
 */
class PaymentSimulator
{
    public function __construct(private FinancialTransaction $transactions, private Clock $clock) {}

    /**
     * Requires current administrator permission and an explicitly enabled local, testing, or demo environment.
     */
    public function authorize(Staff $staff): void
    {
        $current = app(Approvals::class)->authorize($staff, 'simulator.use');

        if (! $current->hasRole('admin') || ! app()->environment(['local', 'testing', 'demo']) || config('withdrawals.simulation_enabled') !== true) {
            throw new AuthorizationException('Payment simulation is restricted to enabled local, testing, or demo environments and administrators.');
        }
    }

    /**
     * Creates a simulated deposit or reserved withdrawal with separate provider evidence.
     */
    public function create(Staff $staff, User $user, string $kind, Money $amount): PaymentSimulation
    {
        $this->authorize($staff);

        if (! in_array($kind, ['deposit', 'withdrawal'], true)) {
            throw new DomainException('Unsupported simulation kind.');
        }

        return $this->transactions->run([$user->id], function () use ($staff, $user, $kind, $amount): PaymentSimulation {
            $customer = User::findOrFail($user->id);

            if (! $customer->is_active || ! $customer->hasVerifiedEmail()) {
                throw new DomainException('Simulation requires an active verified customer.');
            }

            app(Wallets::class)->provision($customer);

            // Create separate simulated evidence while exercising ordinary customer eligibility checks.
            if ($kind === 'deposit') {
                if ($amount->kobo < config('payments.minimum_net') || $amount->kobo > config('payments.maximum_net')) {
                    throw new DomainException('Simulation respects the ordinary net deposit limits.');
                }

                $subject = new Deposit;
                $subject->forceFill(['user_id' => $customer->id, 'request_key' => (string) Str::uuid(), 'reference' => 'ajo-'.Str::uuid(),
                    'payer_email' => $customer->email, 'requested_net' => $amount->kobo, 'origin' => 'simulated',
                    'state' => PendingConfirmation::class, 'created_at' => $this->clock->now(), 'next_verify_at' => $this->clock->now()->addMinutes(15)])->save();
                $evidence = ['reference' => $subject->reference, 'domain' => 'test', 'currency' => 'NGN', 'status' => 'pending',
                    'customer' => ['email' => $customer->email], 'amount' => $amount->kobo + 1000, 'fees' => 1000,
                    'requested_amount' => $amount->kobo, 'paid_at' => $this->clock->now()->toIso8601String()];
            } else {
                $destination = new SavedDestination;
                $destination->forceFill(['user_id' => $customer->id, 'origin' => 'simulated', 'label' => 'Simulated bank',
                    'details' => ['account_number' => '0000000000', 'account_name' => 'Simulated recipient', 'bank_code' => '000',
                        'bank_name' => 'Simulated bank', 'recipient_code' => 'RCP_simulated_'.Str::uuid()]])->save();
                $subject = app(Withdrawals::class)->request($customer, $destination, $amount, (string) Str::uuid(), $staff);
                $evidence = ['reference' => $subject->reference, 'domain' => 'test', 'currency' => 'NGN', 'status' => 'pending',
                    'amount' => $amount->kobo, 'recipient' => ['recipient_code' => $subject->destination['recipient_code']], 'fee_charged' => $subject->reserved_fee];
            }

            $simulation = new PaymentSimulation;
            $simulation->forceFill(['subject_type' => $subject->getMorphClass(), 'subject_id' => $subject->id,
                'reference' => $subject->reference, 'evidence' => $evidence])->save();
            $simulation->forceFill(['evidence' => ['id' => $simulation->id + 8000000000, ...$evidence]])->save();
            app(AuditTrail::class)->record('simulation.created', $simulation, ['customer_id' => $customer->id, 'kind' => $kind], $staff);

            return $simulation;
        });
    }

    /**
     * Changes simulated provider evidence and emits the selected delivery or ordering scenario.
     */
    public function observe(Staff $staff, PaymentSimulation $simulation, string $scenario): void
    {
        $this->authorize($staff);

        if (! in_array($scenario, ['success', 'failure', 'reversal', 'missing', 'duplicate', 'reordered', 'conflicting'], true)) {
            throw new DomainException('Unsupported simulation scenario.');
        }

        $class = $simulation->subject_type;

        if (! in_array($class, [Deposit::class, WithdrawalRequest::class], true)) {
            throw new DomainException('Unsupported simulated subject.');
        }

        $subject = $class::findOrFail($simulation->subject_id);
        $this->transactions->run([$subject->user_id], function () use ($staff, $simulation, $scenario, $subject): void {
            if ($subject->origin !== 'simulated') {
                throw new DomainException('Real provider transactions cannot be simulated.');
            }

            if ($subject instanceof WithdrawalRequest && $subject->submitted_at === null) {
                throw new DomainException('Complete ordinary staff review and dispatch before simulating payout outcomes.');
            }

            $evidence = $simulation->refresh()->evidence;

            // Change provider evidence without assigning the local financial outcome directly.
            $evidence['status'] = match ($scenario) {
                'failure' => 'failed', 'reversal' => 'reversed', default => 'success'
            };
            $event = $subject instanceof Deposit ? 'charge.'.$evidence['status'] : 'transfer.'.$evidence['status'];
            $data = ['reference' => $subject->reference];

            if ($scenario === 'conflicting') {
                $evidence['amount']++;
            }

            if ($subject instanceof Deposit && $scenario === 'reversal') {
                if ($subject->provider_id === null) {
                    throw new DomainException('Recognize the simulated deposit before reversing it.');
                }

                $simulation->refund_id = (string) ($simulation->id + 9000000000);
                $event = 'refund.processed';
                $data['id'] = $simulation->refund_id;
            }

            $simulation->forceFill(['evidence' => $evidence])->save();
            app(AuditTrail::class)->record('simulation.observed', $simulation, ['scenario' => $scenario], $staff);

            // Vary receipt delivery independently of the saved simulated provider outcome.
            if ($scenario !== 'missing') {
                if ($scenario === 'reordered') {
                    $this->emit($subject instanceof Deposit ? 'charge.failed' : 'transfer.failed', $data);
                }

                $this->emit($event, $data);

                if ($scenario === 'duplicate') {
                    $this->emit($event, $data);
                }
            }
        });
    }

    /**
     * Signs simulated observations with the dedicated key before sending them through the inbox.
     *
     * @param  array<string, mixed>  $data
     */
    private function emit(string $event, array $data): void
    {
        $secret = config('withdrawals.simulation_signing_key');

        if (! is_string($secret) || strlen($secret) < 32) {
            throw new DomainException('A dedicated simulator signing key is required.');
        }

        $raw = json_encode(['event' => $event, 'data' => $data], JSON_THROW_ON_ERROR);
        app(PaymentInbox::class)->receiveSimulated($raw, hash_hmac('sha512', $raw, $secret));
    }
}
