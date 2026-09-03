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
    /**
     * Both punches are there but the clock does not add up to a shift:
     * under four hours, or a length no shift can be (a pairing that ran
     * across midnight onto the wrong day, or an in and out at the same
     * minute). Neither the app nor this code may guess what such a day
     * was worth — DEC-20260903-005.
     */
    case HoursUnclear = 'hours_unclear';
    /**
     * The report calls it a week off and the person worked a shift on it.
     * What that is owed — pay, a day back, nothing — is the factory's to
     * say, so the day waits for a person rather than quietly recording a
     * rest day somebody did not get.
     */
    case WorkedOnWeekOff = 'worked_on_week_off';
}
