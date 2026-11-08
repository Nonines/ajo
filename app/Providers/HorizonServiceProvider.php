<?php

namespace App\Providers;

use App\Models\Staff;
use Illuminate\Http\Request;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

/**
 * Protects queue monitoring with the staff guard in every environment.
 */
class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Requires an active administrator with monitoring permission and configured app authentication.
     */
    protected function authorization(): void
    {
        // Apply staff authorization in every environment, including local.
        Horizon::auth(function (Request $request): bool {
            $staff = $request->user('staff');

            return $staff instanceof Staff
                && $staff->is_active
                && $staff->hasRole('admin')
                && $staff->can('horizon.view')
                && filled($staff->getAppAuthenticationSecret());
        });
    }
}
