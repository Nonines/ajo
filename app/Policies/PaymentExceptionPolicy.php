<?php

namespace App\Policies;

use App\Models\PaymentException;
use App\Models\Staff;

/**
 * Allows authorized investigation while preventing direct creation or deletion of evidence.
 */
class PaymentExceptionPolicy
{
    public function viewAny(Staff $staff): bool
    {
        return $staff->is_active && $staff->can('exceptions.manage');
    }

    public function view(Staff $staff, PaymentException $exception): bool
    {
        return $this->viewAny($staff);
    }

    public function update(Staff $staff, PaymentException $exception): bool
    {
        return $this->viewAny($staff);
    }

    public function create(Staff $staff): bool
    {
        return false;
    }

    public function delete(Staff $staff, PaymentException $exception): bool
    {
        return false;
    }
}
