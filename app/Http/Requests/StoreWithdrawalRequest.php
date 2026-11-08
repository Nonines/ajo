<?php

namespace App\Http\Requests;

use App\Support\Money\Money;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates destination selection and positive, representable withdrawal amounts.
 */
class StoreWithdrawalRequest extends FormRequest
{
    /**
     * Requires an active, email-verified customer before validating the operation.
     */
    public function authorize(): bool
    {
        $user = $this->user('web');

        return $user !== null && $user->is_active && $user->hasVerifiedEmail();
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return ['destination_id' => ['required', 'integer'], 'request_key' => ['required', 'uuid'],
            'amount' => ['bail', 'required', 'string', 'regex:/^(0|[1-9][0-9]*)(\.[0-9]{1,2})?$/D',
                function (string $attribute, mixed $value, Closure $fail): void {
                    try {
                        if ($this->money()->kobo <= 0) {
                            $fail('Enter a positive withdrawal amount.');
                        }
                    } catch (\Throwable) {
                        $fail('The withdrawal exceeds supported integer kobo.');
                    }
                }]];
    }

    /**
     * Converts the validated decimal-naira input to integer kobo without floating-point arithmetic.
     */
    public function money(): Money
    {
        return Money::fromNaira($this->string('amount')->toString());
    }
}
