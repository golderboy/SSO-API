<?php

namespace App\Models;

use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Passport\Client;
use Laravel\Passport\Scope;

class SsoOAuthClient extends Client
{
    /**
     * @param  Scope[]  $scopes
     */
    public function skipsAuthorization(
        Authenticatable $user,
        array $scopes,
    ): bool {
        return request()->attributes->get('sso_broker_approved') === true;
    }
}
