<?php

namespace App\Http\Requests;

use App\Support\Money;
use Brick\Math\BigInteger;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class StoreDepositRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user('web');

        return $user !== null && $user->is_active && $user->hasVerifiedEmail();
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'request_key' => ['required', 'uuid'],
            'amount' => ['bail', 'required', 'string', 'regex:/^[1-9][0-9]{0,8}(\.[0-9]{1,2})?$/D', function (string $attribute, mixed $value, Closure $fail): void {
                $net = $this->money()->kobo;
                if ($net < config('payments.minimum_net') || $net > config('payments.maximum_net')) {
                    $fail('Net deposits must be between NGN 1,000 and NGN 100,000,000.');
                }
            }],
        ];
    }

    /**
     * Converts the validated decimal-naira input to integer kobo without floating-point arithmetic.
     */
    public function money(): Money
    {
        [$naira, $fraction] = array_pad(explode('.', $this->string('amount')->toString(), 2), 2, '');

        return new Money((string) BigInteger::of($naira)->multipliedBy(100)->plus(str_pad($fraction, 2, '0')));
    }
}
