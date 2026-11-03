<?php

namespace App\Support;

use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;

class StaffAppAuthentication extends AppAuthentication
{
    /** @return array<string> */
    public function getRecoveryCodes(HasAppAuthenticationRecovery $user): array
    {
        // An exhausted code list must fail validation rather than throw during login.
        return $user->getAppAuthenticationRecoveryCodes() ?? [];
    }
}
