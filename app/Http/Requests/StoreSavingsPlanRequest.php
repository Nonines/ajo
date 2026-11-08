<?php

namespace App\Http\Requests;

use App\Support\Money\Money;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates draft contract choices and exact, representable principal amounts.
 */
class StoreSavingsPlanRequest extends FormRequest
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
        return ['request_key' => ['required', 'uuid'], 'version_id' => ['required', 'integer', 'exists:savings_product_versions,id'],
            'days' => ['required', 'integer', 'min:30', 'multiple_of:30'],
            'amount' => ['bail', 'required', 'string', 'regex:/^(0|[1-9][0-9]*)(\.[0-9]{1,2})?$/D', function (string $attribute, mixed $value, Closure $fail): void {
                try {
                    $this->money();
                } catch (\Throwable) {
                    $fail('The principal exceeds supported integer kobo.');
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
