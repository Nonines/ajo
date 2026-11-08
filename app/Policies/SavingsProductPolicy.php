<?php

namespace App\Policies;

use App\Models\SavingsProduct;
use App\Models\Staff;

/**
 * Restricts product management to active administrators and preserves published history.
 */
class SavingsProductPolicy
{
    public function viewAny(Staff $staff): bool
    {
        return $staff->is_active && $staff->hasRole('admin') && $staff->can('products.manage');
    }

    public function create(Staff $staff): bool
    {
        return $this->viewAny($staff);
    }

    public function update(Staff $staff, SavingsProduct $product): bool
    {
        return $this->viewAny($staff);
    }

    public function delete(Staff $staff, SavingsProduct $product): bool
    {
        return false;
    }
}
