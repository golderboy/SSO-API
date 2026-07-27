<?php

namespace App\Exceptions;

use RuntimeException;

class IdentityResolutionException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('The verified external identity was denied.');
    }
}
