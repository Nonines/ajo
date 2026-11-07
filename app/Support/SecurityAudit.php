<?php

namespace App\Support;

use App\Models\Deposit;
use App\Models\LedgerAccount;
use App\Models\PaymentException;
use App\Models\SavingsPlan;
use App\Models\Staff;
use App\Models\User;
use App\Models\WithdrawalRequest;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Laravel\Fortify\Events\RecoveryCodeReplaced;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Laravel\Passkeys\Events\PasskeyDeleted;
use Laravel\Passkeys\Events\PasskeyRegistered;
use Laravel\Passkeys\Events\PasskeyVerified;

class SecurityAudit
{
    public static function register(): void
    {
        Event::listen([
            Login::class, Logout::class,
            Failed::class, Lockout::class,
            PasswordReset::class, Registered::class,
            Verified::class, TwoFactorAuthenticationEnabled::class,
            TwoFactorAuthenticationConfirmed::class, TwoFactorAuthenticationDisabled::class,
            TwoFactorAuthenticationFailed::class, RecoveryCodesGenerated::class,
            RecoveryCodeReplaced::class, PasskeyRegistered::class,
            PasskeyDeleted::class, PasskeyVerified::class,
        ], function (object $event): void {
            $actor = $event->user ?? null;
            $subject = $actor instanceof User || $actor instanceof Staff ? $actor : null;
            app(AuditTrail::class)->record('security.'.class_basename($event), $subject,
                ['guard' => $event->guard ?? null], $subject);
        });
        foreach ([Staff::class, User::class] as $class) {
            Event::listen('eloquent.updated: '.$class, function (Model $model): void {
                $changes = $model->getChanges();
                unset($changes['updated_at'], $changes['remember_token']);
                if ($changes === []) {
                    return;
                }
                $before = array_intersect_key($model->getRawOriginal(), $changes);
                app(AuditTrail::class)->record($model instanceof Staff ? 'staff.updated' : 'customer.updated', $model,
                    changes: ['before' => $before, 'after' => $changes]);
            });
        }
        foreach ([Deposit::class, SavingsPlan::class, LedgerAccount::class,
            WithdrawalRequest::class, PaymentException::class, User::class] as $class) {
            Event::listen('eloquent.retrieved: '.$class, function (Model $model): void {
                $staff = Auth::guard('staff')->user();
                if ($staff instanceof Staff && $staff->is_active && app(AuditTrail::class)->staffContext()) {
                    app(AuditTrail::class)->record('staff.financial_read', $model,
                        ['customer_id' => $model instanceof User ? $model->id : $model->getAttribute('user_id')], $staff);
                }
            });
        }
    }

    public function staffPermissions(Staff $staff): void
    {
        app(AuditTrail::class)->record('staff.permissions', $staff, ['roles' => $staff->roles()->pluck('name')->all(),
            'permissions' => $staff->permissions()->pluck('name')->all()]);
    }
}
