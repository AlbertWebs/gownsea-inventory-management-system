<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Notifications\StaffPasswordResetNotification;
use App\Security\PermissionCatalog;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'role', 'phone', 'is_active', 'permissions'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_pending_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'two_factor_secret' => 'encrypted',
            'two_factor_pending_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_last_used_step' => 'integer',
            'permissions' => 'array',
        ];
    }

    public function locations(): BelongsToMany
    {
        return $this->belongsToMany(Location::class)->withPivot('is_primary')->withTimestamps();
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new StaffPasswordResetNotification($token));
    }

    public function mayViewAllLocations(): bool
    {
        return in_array($this->role, ['super_admin', 'management'], true);
    }

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, PermissionCatalog::normalizeForRole($this->role, $this->permissions), true);
    }

    /** @return list<string> */
    public function effectivePermissions(): array
    {
        return PermissionCatalog::normalizeForRole($this->role, $this->permissions);
    }
}
