<?php

namespace App\Exceptions;

use RuntimeException;

class BrokerRequestException extends RuntimeException
{
    public function __construct(
        public readonly string $oauthError,
        string $message = 'The authorization request is invalid.',
        public readonly int $status = 400,
    ) {
        parent::__construct($message);
    }
}
