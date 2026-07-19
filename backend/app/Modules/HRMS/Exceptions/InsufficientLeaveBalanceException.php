<?php

namespace App\Modules\HRMS\Exceptions;

use App\Exceptions\DomainException;
use RuntimeException;

class InsufficientLeaveBalanceException extends RuntimeException implements DomainException
{
    public static function forEmployee(int $employeeId, int $leaveTypeId, string $remaining, string $requested): self
    {
        return new self(
            "Insufficient leave balance for employee #{$employeeId}, leave type #{$leaveTypeId}: ".
            "remaining {$remaining}, requested {$requested}."
        );
    }

    public static function noAllocation(int $employeeId, int $leaveTypeId, int $year): self
    {
        return new self(
            "No leave balance allocated for employee #{$employeeId}, leave type #{$leaveTypeId}, year {$year}."
        );
    }
}
