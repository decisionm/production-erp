<?php

namespace App\Modules\Core\Exceptions;

use App\Exceptions\DomainException;
use RuntimeException;

class RoleInUseException extends RuntimeException implements DomainException
{
    public static function forRole(string $roleName, int $userCount): self
    {
        return new self("Role \"{$roleName}\" is assigned to {$userCount} user(s) and cannot be deleted.");
    }
}
