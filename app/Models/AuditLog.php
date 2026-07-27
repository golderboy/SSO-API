<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'public_id',
    'actor_user_id',
    'action',
    'auditable_type',
    'auditable_id',
    'target_public_id',
    'ip_hash',
    'user_agent_hash',
    'context',
    'created_at',
])]
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
