<?php

namespace App\Http\Requests;

use App\Models\SavingsPlan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSavingsPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user('web');
        $plan = $this->route('plan');
        abort_unless($plan instanceof SavingsPlan && $user !== null && $plan->user_id === $user->id, 404);

        return $user->is_active && $user->hasVerifiedEmail();
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        $renewal = fn (): bool => $this->input('action') === 'rollover' && $this->boolean('enabled');

        return ['action' => ['required', 'in:accept,fund,cancel,terminate,rollover'],
            'token' => ['required_if:action,terminate', 'string', 'size:64'],
            'waiver_id' => ['nullable', 'integer', 'min:1'],
            'enabled' => ['required_if:action,rollover', 'boolean'],
            'days' => [Rule::requiredIf($renewal), 'nullable', 'integer', 'min:30', 'multiple_of:30'],
            'amount_mode' => [Rule::requiredIf($renewal), 'nullable', 'in:original_principal,all_net'],
            'consent' => [Rule::when($renewal, ['required', 'accepted'], ['exclude'])]];
    }
}
