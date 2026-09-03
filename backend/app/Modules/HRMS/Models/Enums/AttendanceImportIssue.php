<?php

namespace App\Modules\HRMS\Models\Enums;

/**
 * Why a punch-report line needs a person before it can reach `attendances`.
 * Classified once, server-side, by AttendanceImportService::classify.
 */
enum AttendanceImportIssue: string
{
    case InNoOut = 'in_no_out';
    case OutNoIn = 'out_no_in';
    case NoPunch = 'no_punch';
    case UnknownEmployee = 'unknown_employee';
}
