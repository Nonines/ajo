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

class SavingsProducts
{
    public function __construct(private FinancialTransaction $transactions, private Clock $clock) {}

    /** @param array<string, mixed> $data */
    public function publish(Staff $staff, array $data, ?SavingsProduct $product = null, ?ApprovalRequest $approval = null): SavingsProduct
    {
        Gate::forUser($staff)->authorize('create', SavingsProduct::class);
        $values = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'], 'disclosure' => ['required', 'string', 'max:10000'],
            'minimum' => ['required', 'regex:/^[1-9][0-9]*$/D'], 'maximum' => ['required', 'regex:/^[1-9][0-9]*$/D'],
            'withholding_bp' => ['required', 'integer', 'between:0,10000'],
            'terms' => ['required', 'array', 'min:1'], 'terms.*.days' => ['required', 'integer', 'min:30', 'multiple_of:30', 'distinct'],
            'terms.*.annual_rate_bp' => ['required', 'integer', 'min:0'],
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
        $contract = ['name' => $values['name'], 'disclosure' => $values['disclosure'], 'minimum' => (string) $minimum->kobo,
            'maximum' => (string) $maximum->kobo, 'withholding_bp' => (int) $values['withholding_bp'], 'terms' => $terms,
            'interest' => 'simple', 'day_count' => 'Actual/365', 'lock' => 'until_maturity', 'fee' => '0'];

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
