<?php

namespace App\Models;

use Database\Factories\AccessGrantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'public_id',
    'user_id',
    'application_id',
    'organization_id',
    'role',
    'permissions',
    'is_active',
    'valid_from',
    'valid_until',
    'revoked_at',
    'created_by',
    'revoked_by',
])]
class AccessGrant extends Model
{
    /** @use HasFactory<AccessGrantFactory> */
    use HasFactory;

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function scopeEffective(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->whereNull('revoked_at')
            ->where(fn (Builder $builder) => $builder
                ->whereNull('valid_from')
                ->orWhere('valid_from', '<=', now()))
            ->where(fn (Builder $builder) => $builder
                ->whereNull('valid_until')
                ->orWhere('valid_until', '>', now()));
    }

    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'is_active' => 'boolean',
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
