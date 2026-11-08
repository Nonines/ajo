<?php

namespace App\Models;

use Database\Factories\StaffFactory;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthentication;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthenticationRecovery;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

/**
 * Keeps staff authentication, roles, and panel access separate from customer identities.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property bool $is_active
 */
#[Fillable(['name', 'email', 'password', 'is_active'])]
#[Hidden(['password', 'remember_token', 'app_authentication_secret', 'app_authentication_recovery_codes'])]
class Staff extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery
{
    /** @use HasFactory<StaffFactory> */
    use HasFactory, HasRoles, InteractsWithAppAuthentication, InteractsWithAppAuthenticationRecovery, Notifiable;

    protected string $guard_name = 'staff';

    /**
     * Requires active staff and the roles allowed for the specific panel.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active && match ($panel->getId()) {
            'admin' => $this->hasRole('admin'),
            'ops' => $this->hasAnyRole(['manager', 'admin']),
            default => false,
        };
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }
}
