<?php

namespace App\Support;

use App\Contracts\Clock;
use App\Models\ApprovalRequest;
use App\Models\SavingsProduct;
use App\Models\SavingsProductVersion;
use App\Models\Staff;
use DomainException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Publishes versioned product terms so changes do not rewrite existing plan contracts.
 */
class SavingsProducts
{
    public function __construct(private FinancialTransaction $transactions, private Clock $clock) {}

    /**
     * Validates product terms and publishes a new version, requiring approval when revising offered rate terms.
     *
     * @param  array<string, mixed>  $data
     */
    public function publish(Staff $staff, array $data, ?SavingsProduct $product = null, ?ApprovalRequest $approval = null): SavingsProduct
    {
        Gate::forUser($staff)->authorize('create', SavingsProduct::class);
        $values = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'], 'disclosure' => ['required', 'string', 'max:10000'],
            'minimum' => ['required', 'regex:/^[1-9][0-9]*$/D'], 'maximum' => ['required', 'regex:/^[1-9][0-9]*$/D'],
            'withholding_bp' => ['required', 'integer', 'between:0,10000'],
            'terms' => ['required', 'array', 'min:1'], 'terms.*.days' => ['required', 'integer', 'min:30', 'multiple_of:30', 'distinct'],
            'terms.*.annual_rate_bp' => ['required', 'integer', 'min:0'],
            'interest' => ['sometimes', 'required', 'in:simple,daily,30_days'],
            'lock' => ['sometimes', 'required', 'in:until_maturity,terminable,uncancellable'],
            'minimum_holding_days' => ['required_if:lock,terminable', 'nullable', 'integer', 'min:1'],
            'forfeiture_bp' => ['sometimes', 'required', 'integer', 'between:0,10000'],
            'principal_penalty_bp' => ['sometimes', 'required', 'integer', 'between:0,10000'],
            'available' => ['sometimes', 'boolean'],
        ])->validate();
        try {
            $minimum = new Money((string) $values['minimum']);
            $maximum = new Money((string) $values['maximum']);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['maximum' => 'Principal limits must fit integer kobo.']);
        }
        if ($minimum->kobo > $maximum->kobo) {
            throw ValidationException::withMessages(['maximum' => 'Maximum principal must be at least the minimum.']);
        }
        $terms = array_map(static fn (array $term): array => ['days' => (int) $term['days'], 'annual_rate_bp' => (int) $term['annual_rate_bp']], array_values($values['terms']));
        usort($terms, static fn (array $a, array $b): int => $a['days'] <=> $b['days']);
        if (($values['lock'] ?? 'until_maturity') === 'terminable' && (int) $values['minimum_holding_days'] >= $terms[0]['days']) {
            throw ValidationException::withMessages(['minimum_holding_days' => 'Minimum holding must be shorter than every offered term.']);
        }
        $contract = ['name' => $values['name'], 'disclosure' => $values['disclosure'], 'minimum' => (string) $minimum->kobo,
            'maximum' => (string) $maximum->kobo, 'withholding_bp' => (int) $values['withholding_bp'], 'terms' => $terms,
            'interest' => $values['interest'] ?? 'simple', 'day_count' => 'Actual/365', 'lock' => $values['lock'] ?? 'until_maturity', 'fee' => '0',
            'minimum_holding_days' => ($values['lock'] ?? '') === 'terminable' ? (int) $values['minimum_holding_days'] : null,
            'forfeiture_bp' => (int) ($values['forfeiture_bp'] ?? 0), 'principal_penalty_bp' => (int) ($values['principal_penalty_bp'] ?? 0),
            'available' => (bool) ($values['available'] ?? true)];

        return $this->transactions->run([], function () use ($staff, $product, $contract, $approval, $data): SavingsProduct {
            $current = $product === null ? new SavingsProduct : SavingsProduct::findOrFail($product->id);
            if ($product !== null && $current->versions()->where('version', $current->current_version)->sole()->contract['terms'] !== $contract['terms']) {
                $evidence = $approval === null ? null : ApprovalRequest::findOrFail($approval->id);
                if ($evidence === null || $evidence->action !== 'product.rate' || $evidence->state !== 'approved'
                    || $evidence->subject_type !== SavingsProduct::class || $evidence->subject_id !== $current->id
                    || $evidence->decided_by !== $staff->id || $evidence->requested_by === $staff->id
                    || $evidence->applied_at !== null || $evidence->payload['data'] !== $data
                    || $evidence->payload['expected_version'] !== $current->current_version) {
                    throw new DomainException('Existing-product rate changes require a maker and a different checker.');
                }
            }
            $number = ($current->current_version ?? 0) + 1;
            $current->forceFill(['name' => $contract['name'], 'current_version' => $number])->save();
            (new SavingsProductVersion)->forceFill(['savings_product_id' => $current->id, 'version' => $number,
                'contract' => $contract, 'published_at' => $this->clock->now()])->save();
            app(AuditTrail::class)->record('product.published', $current, ['version' => $number, 'contract' => $contract], $staff);

            return $current;
        });
    }
}
