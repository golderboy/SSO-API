<?php

namespace App\Models;

use App\Enums\IdentityProvider;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'public_id',
    'user_id',
    'provider',
    'subject_hash',
    'identity_match_hash',
    'linked_at',
    'last_authenticated_at',
])]
#[Hidden([
    'subject_hash',
    'identity_match_hash',
])]
class ExternalIdentity extends Model
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'provider' => IdentityProvider::class,
            'linked_at' => 'datetime',
            'last_authenticated_at' => 'datetime',
        ];
    }
}
