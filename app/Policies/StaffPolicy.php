<?php

namespace App\Policies;

use App\Models\Staff;

/**
 * Restricts staff management and prevents deletion of staff identities.
 */
class StaffPolicy
{
    public function viewAny(Staff $user): bool
    {
        return $user->is_active && $user->can('staff.manage');
    }

    public function view(Staff $user, Staff $staff): bool
    {
        return $this->viewAny($user);
    }

    public function create(Staff $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(Staff $user, Staff $staff): bool
    {
        return $this->viewAny($user);
    }

    public function delete(Staff $user, Staff $staff): bool
    {
        return false;
    }

    public function deleteAny(Staff $user): bool
    {
        return false;
    }
}
