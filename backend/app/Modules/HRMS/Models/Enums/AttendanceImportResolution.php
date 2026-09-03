<?php

namespace App\Modules\HRMS\Models\Enums;

/**
 * What a reviewed line says the day WAS. The first four are
 * AttendanceStatus and are written to `attendances`; `week_off` is
 * deliberately NOT an AttendanceStatus (adding a status to the live enum
 * touches payroll's day counting, which Q34 leaves unconfirmed) — it stays
 * on the import line and nothing is written for that day.
 */
enum AttendanceImportResolution: string
{
    case Present = 'present';
    case HalfDay = 'half_day';
    case Absent = 'absent';
    case OnLeave = 'on_leave';
    case WeekOff = 'week_off';

    public function attendanceStatus(): ?AttendanceStatus
    {
        return match ($this) {
            self::Present => AttendanceStatus::Present,
            self::HalfDay => AttendanceStatus::HalfDay,
            self::Absent => AttendanceStatus::Absent,
            self::OnLeave => AttendanceStatus::OnLeave,
            self::WeekOff => null,
        };
    }

    /** The one-letter code the month sheet prints. */
    public function sheetCode(): string
    {
        return match ($this) {
            self::Present => 'P',
            self::HalfDay => 'H',
            self::Absent => 'A',
            self::OnLeave => 'L',
            self::WeekOff => 'W',
        };
    }
}
