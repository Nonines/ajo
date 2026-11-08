<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates customer bank details before independent provider resolution.
 */
class StoreDestinationRequest extends FormRequest
{
    /**
     * Requires an active, email-verified customer before validating the operation.
     */
    public function authorize(): bool
    {
        $user = $this->user('web');

        return $user !== null && $user->is_active && $user->hasVerifiedEmail();
    }

    /** @return array<string, array<string>> */
    public function rules(): array
    {
        return ['label' => ['required', 'string', 'max:100'], 'account_number' => ['required', 'regex:/^[0-9]{10}$/D'],
            'bank_code' => ['required', 'regex:/^[0-9]{3,12}$/D']];
    }
}
