<?php

namespace App\Support;

use App\Contracts\PaymentGateway;
use App\Models\SavedDestination;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\Validator;

class Destinations
{
    public function __construct(private PaymentGateway $gateway, private FinancialTransaction $transactions, private AuditTrail $audit) {}

    public function save(User $user, string $label, string $accountNumber, string $bankCode): SavedDestination
    {
        Validator::make(compact('label', 'accountNumber', 'bankCode'), [
            'label' => ['required', 'string', 'max:100'], 'accountNumber' => ['required', 'regex:/^[0-9]{10}$/D'],
            'bankCode' => ['required', 'regex:/^[0-9]{3,12}$/D'],
        ])->validate();
        $current = User::findOrFail($user->id);
        if (! $current->is_active || ! $current->hasVerifiedEmail()) {
            throw new DomainException('Only active verified customers can save destinations.');
        }
        $resolved = $this->gateway->resolveAccount($accountNumber, $bankCode);
        if (($resolved['account_number'] ?? null) !== $accountNumber || ! is_string($resolved['account_name'] ?? null) || $resolved['account_name'] === '') {
            throw new DomainException('Bank account resolution did not match the requested destination.');
        }
        $recipient = $this->gateway->createRecipient($accountNumber, $bankCode, $resolved['account_name']);
        $details = $recipient['details'] ?? null;
        if (($recipient['currency'] ?? null) !== 'NGN' || ($recipient['type'] ?? null) !== 'nuban' || ($recipient['active'] ?? null) !== true
            || ! is_string($recipient['recipient_code'] ?? null) || ! str_starts_with($recipient['recipient_code'], 'RCP_')
            || ! is_array($details) || ($details['account_number'] ?? null) !== $accountNumber || ($details['bank_code'] ?? null) !== $bankCode) {
            throw new DomainException('Recipient creation did not match the resolved bank account.');
        }

        return $this->transactions->run([$user->id], function () use ($user, $label, $accountNumber, $bankCode, $resolved, $recipient, $details): SavedDestination {
            $customer = User::findOrFail($user->id);
            if (! $customer->is_active || ! $customer->hasVerifiedEmail()) {
                throw new DomainException('Customer became ineligible while resolving the destination.');
            }
            $destination = new SavedDestination;
            $destination->forceFill(['user_id' => $user->id, 'label' => $label, 'origin' => 'real', 'details' => [
                'account_number' => $accountNumber, 'bank_code' => $bankCode, 'account_name' => $resolved['account_name'],
                'bank_name' => $details['bank_name'] ?? $bankCode, 'recipient_code' => $recipient['recipient_code'],
            ]])->save();
            $this->audit->record('destination.saved', $destination, ['customer_id' => $user->id, 'account_number' => $accountNumber], $customer);

            return $destination;
        });
    }
}
