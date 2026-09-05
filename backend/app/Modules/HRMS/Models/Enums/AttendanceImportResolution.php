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

    /**
     * WHAT THE MONTH SHEET PRINTS IN A DAY'S CELL.
     *
     * The factory's own paper sheet is the reference, and it does not use
     * one letter each: a week off is W/O there and a half day H/D, because
     * W and H on their own are read wrong across a 31-column grid by
     * somebody totalling a row by eye. `Leave` is spelled out for the same
     * reason — an L beside a P and an A is the one a payroll clerk
     * mistakes.
     */
    public function sheetCode(): string
    {
        return match ($this) {
            self::Present => 'P',
            self::HalfDay => 'HD',
            self::Absent => 'A',
            self::OnLeave => 'Leave',
            self::WeekOff => 'WO',
        };
    }
}
