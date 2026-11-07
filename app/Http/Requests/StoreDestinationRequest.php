<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDestinationRequest extends FormRequest
{
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
