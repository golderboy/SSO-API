<?php

namespace App\Models;

use App\Enums\IdentityProvider;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Passport\Client;

#[Fillable([
    'application_id',
    'oauth_client_id',
    'allowed_providers',
])]
class ApplicationSsoConfig extends Model
{
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function oauthClient(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'oauth_client_id');
    }

    protected function casts(): array
    {
        return [
            'allowed_providers' => AsEnumCollection::of(
                IdentityProvider::class,
            ),
        ];
    }
}
