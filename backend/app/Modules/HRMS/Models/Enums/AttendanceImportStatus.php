<?php

namespace App\Modules\HRMS\Models\Enums;

enum AttendanceImportStatus: string
{
    case Review = 'review';
    case Applied = 'applied';
}
