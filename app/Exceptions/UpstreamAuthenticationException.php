<?php

namespace App\Exceptions;

use RuntimeException;

class UpstreamAuthenticationException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('The upstream identity provider response was rejected.');
    }
}
