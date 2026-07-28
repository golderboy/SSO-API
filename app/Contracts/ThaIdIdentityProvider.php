<?php

namespace App\Contracts;

use App\Data\VerifiedExternalIdentity;

interface ThaIdIdentityProvider
{
    public function authenticate(string $authorizationCode): VerifiedExternalIdentity;
}
