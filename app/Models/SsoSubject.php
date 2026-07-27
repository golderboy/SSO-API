<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;

#[Fillable([
    'user_id',
])]
class SsoSubject extends Authenticatable implements OAuthenticatable
{
    use HasApiTokens, HasFactory;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
