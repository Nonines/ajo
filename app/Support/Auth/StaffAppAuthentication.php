<?php

namespace App\Support\Auth;

use App\Models\Staff;
use App\Support\Audit\AuditTrail;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Illuminate\Contracts\Auth\Authenticatable;
use SensitiveParameter;

/**
 * Adds staff MFA audit events while leaving code verification to the panel authenticator.
 */
class StaffAppAuthentication extends AppAuthentication
{
    private ?Staff $challengedStaff = null;

    /**
     * Retains the challenged staff identity for subsequent verification audit events.
     */
    public function getChallengeFormComponents(Authenticatable $user): array
    {
        $this->challengedStaff = $user instanceof Staff ? $user : null;

        return parent::getChallengeFormComponents($user);
    }

    /**
     * Audits the authenticator result without retaining the submitted code.
     */
    public function verifyCode(#[SensitiveParameter] string $code, #[SensitiveParameter] ?string $secret = null, bool $shouldPreventCodeReuse = false): bool
    {
        $accepted = parent::verifyCode($code, $secret, $shouldPreventCodeReuse);

        if ($this->challengedStaff !== null) {
            app(AuditTrail::class)->record($accepted ? 'security.staff_mfa_accepted' : 'security.staff_mfa_rejected',
                $this->challengedStaff, actor: $this->challengedStaff);
        }

        return $accepted;
    }

    /**
     * Audits recovery verification against the supplied or challenged staff identity.
     */
    public function verifyRecoveryCode(#[SensitiveParameter] string $recoveryCode, ?HasAppAuthenticationRecovery $user = null): bool
    {
        $accepted = parent::verifyRecoveryCode($recoveryCode, $user);
        $staff = $user instanceof Staff ? $user : $this->challengedStaff;

        if ($staff !== null) {
            app(AuditTrail::class)->record($accepted ? 'security.staff_recovery_accepted' : 'security.staff_recovery_rejected', $staff, actor: $staff);
        }

        return $accepted;
    }

    /** @return array<string> */
    public function getRecoveryCodes(HasAppAuthenticationRecovery $user): array
    {
        // An exhausted code list must fail validation rather than throw during login.
        return $user->getAppAuthenticationRecoveryCodes() ?? [];
    }
}
