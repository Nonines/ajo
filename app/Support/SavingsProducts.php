<?php

namespace App\Support;

use App\Contracts\Clock;
use App\Models\SavingsProduct;
use App\Models\SavingsProductVersion;
use App\Models\Staff;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SavingsProducts
{
    public function __construct(private FinancialTransaction $transactions, private Clock $clock) {}

    /** @param array<string, mixed> $data */
    public function publish(Staff $staff, array $data, ?SavingsProduct $product = null): SavingsProduct
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

        return $this->transactions->run([], function () use ($product, $contract): SavingsProduct {
            $current = $product === null ? new SavingsProduct : SavingsProduct::findOrFail($product->id);
            $number = ($current->current_version ?? 0) + 1;
            $current->forceFill(['name' => $contract['name'], 'current_version' => $number])->save();
            (new SavingsProductVersion)->forceFill(['savings_product_id' => $current->id, 'version' => $number,
                'contract' => $contract, 'published_at' => $this->clock->now()])->save();

            return $current;
        });
    }
}
