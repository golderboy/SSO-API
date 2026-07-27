<?php

namespace App\Enums;

enum SystemRole: string
{
    case Admin = 'admin';
    case SuperAdmin = 'super_admin';
    case User = 'user';

    public function isAdministrative(): bool
    {
        return $this === self::Admin || $this === self::SuperAdmin;
    }
}
