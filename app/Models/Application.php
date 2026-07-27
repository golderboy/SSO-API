<?php

namespace App\Models;

use Database\Factories\ApplicationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'public_id',
    'name',
    'slug',
    'base_url',
    'require_organization_match',
    'is_active',
])]
class Application extends Model
{
    /** @use HasFactory<ApplicationFactory> */
    use HasFactory, SoftDeletes;

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApplicationApiKey::class);
    }

    public function accessGrants(): HasMany
    {
        return $this->hasMany(AccessGrant::class);
    }

    protected function casts(): array
    {
        return [
            'require_organization_match' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
