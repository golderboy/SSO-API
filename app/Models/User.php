<?php

namespace App\Models;

use App\Enums\SystemRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable([
    'public_id',
    'name',
    'email',
    'password',
    'is_active',
    'system_role',
    'last_login_at',
])]
#[Hidden([
    'password',
    'remember_token',
    'cid_hash',
    'cid_encrypted',
    'provider_cid_hash',
    'admin_slot',
])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function accessGrants(): HasMany
    {
        return $this->hasMany(AccessGrant::class);
    }

    public function externalIdentities(): HasMany
    {
        return $this->hasMany(ExternalIdentity::class);
    }

    public function ssoSubject(): HasOne
    {
        return $this->hasOne(SsoSubject::class);
    }

    public function isAdmin(): bool
    {
        return $this->system_role === SystemRole::Admin;
    }

    public function isSuperAdmin(): bool
    {
        return $this->system_role === SystemRole::SuperAdmin;
    }

    public function isAdministrative(): bool
    {
        return $this->system_role?->isAdministrative() ?? false;
    }

    protected static function booted(): void
    {
        static::saving(function (User $user): void {
            $role = $user->system_role === null
                ? SystemRole::User
                : ($user->system_role instanceof SystemRole
                ? $user->system_role
                : SystemRole::from((string) $user->system_role));

            $user->system_role = $role;
            $user->admin_slot = $role === SystemRole::Admin ? 1 : null;
        });
    }

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
            'cid_encrypted' => 'encrypted',
            'is_active' => 'boolean',
            'system_role' => SystemRole::class,
            'last_login_at' => 'datetime',
        ];
    }
}
