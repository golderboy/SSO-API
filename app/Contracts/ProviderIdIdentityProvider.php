<?php

namespace App\Contracts;

use App\Data\VerifiedExternalIdentity;

interface ProviderIdIdentityProvider
{
    public function authenticate(string $authorizationCode): VerifiedExternalIdentity;
}
