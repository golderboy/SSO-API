<?php

namespace App\Models;

use App\Enums\AuthenticationTransactionStatus;
use App\Enums\IdentityProvider;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'public_id',
    'application_sso_config_id',
    'browser_session_hash',
    'downstream_request',
    'upstream_state_hash',
    'selected_provider',
    'status',
    'user_id',
    'access_grant_id',
    'organization_id',
    'expires_at',
    'authenticated_at',
    'consumed_at',
])]
#[Hidden([
    'browser_session_hash',
    'downstream_request',
    'upstream_state_hash',
])]
class AuthenticationTransaction extends Model
{
    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function ssoConfig(): BelongsTo
    {
        return $this->belongsTo(
            ApplicationSsoConfig::class,
            'application_sso_config_id',
        );
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function accessGrant(): BelongsTo
    {
        return $this->belongsTo(AccessGrant::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    protected function casts(): array
    {
        return [
            'downstream_request' => 'encrypted:array',
            'selected_provider' => IdentityProvider::class,
            'status' => AuthenticationTransactionStatus::class,
            'expires_at' => 'immutable_datetime',
            'authenticated_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
        ];
    }
}
