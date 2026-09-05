<?php

namespace Tests\Unit\HRMS;

use App\Modules\HRMS\Models\Enums\AttendanceImportIssue as Issue;
use App\Modules\HRMS\Models\Enums\AttendanceImportResolution as Resolution;
use App\Modules\HRMS\Services\AttendanceImportService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * THE ISSUE TABLE, pinned row by row. A line either resolves itself or
 * names why a person has to look at it; nothing here writes anything.
 *
 * THE DAY IS JUDGED BY HOURS, NOT BY THE APP'S LABEL (DEC-20260903-005).
 * The punch app measures every shift against a longer general day, so in
 * July 2026 it printed "HD" on 245 days of which 232 had seven hours or
 * more on the clock — real eight-hour shifts that punched out a few
 * minutes early. Reading that label would have paid 232 full shifts as
 * halves. The owner set the two anchors: eight hours is a full day, four
 * hours is a half day; the shift tolerance below is the implementation of
 * that, and is stated here so it can be argued with.
 */
class AttendanceImportClassifyTest extends TestCase
{
    /** @return array<string, array{0: string, 1: ?string, 2: ?string, 3: int, 4: bool, 5: ?Issue, 6: ?Resolution}> */
    public static function rows(): array
    {
        return [
            // ---- judged by the clock ------------------------------------
            'a full eight-hour shift' => ['FD', '06:00', '14:00', 480, true, null, Resolution::Present],
            'a shift that clocked out early is still the shift' => ['HD', '06:25', '14:13', 468, true, null, Resolution::Present],
            'the app said half, the clock says seven hours' => ['HD', '06:43', '14:24', 421, true, null, Resolution::Present],
            'exactly seven hours is a full day' => ['HD', '07:00', '14:00', 420, true, null, Resolution::Present],
            'a genuine half day' => ['FD', '10:00', '14:05', 245, true, null, Resolution::HalfDay],
            'four hours is a half day' => ['HD', '10:00', '14:00', 240, true, null, Resolution::HalfDay],
            'just under seven hours is a half day' => ['FD', '07:00', '13:50', 419, true, null, Resolution::HalfDay],
            'a long day is a full day, overtime is counted elsewhere' => ['FD', '09:00', '19:30', 630, true, null, Resolution::Present],

            // ---- the clock does not add up ------------------------------
            'under four hours is nobody\'s call to make' => ['FD', '10:00', '13:00', 180, true, Issue::HoursUnclear, null],
            'an impossible twenty-two hour day' => ['FD', '00:14', '22:11', 1317, true, Issue::HoursUnclear, null],
            'in and out at the same minute' => ['HD', '06:38', '06:38', 453, true, Issue::HoursUnclear, null],
            'both punches but no hours recorded' => ['FD', '09:00', '18:00', 0, true, Issue::HoursUnclear, null],

            // ---- a missing punch outranks the hours ---------------------
            'in without out' => ['FD', '10:10', null, 0, true, Issue::InNoOut, null],
            'half day in without out' => ['HD', '10:10', null, 0, true, Issue::InNoOut, null],
            'out without in' => ['FD', null, '20:20', 0, true, Issue::OutNoIn, null],
            'absent, no punches' => ['Absent', null, null, 0, true, Issue::NoPunch, null],
            'dash status, no punches' => ['-', null, null, 0, true, Issue::NoPunch, null],
            'unrecognised status, no punches' => ['Holiday', null, null, 0, true, Issue::NoPunch, null],

            // ---- week off ------------------------------------------------
            'week off, nobody came in' => ['Week Off', null, null, 0, true, null, Resolution::WeekOff],
            'week off but they worked a shift is not a week off' => ['Week Off', '09:00', '18:00', 480, true, Issue::WorkedOnWeekOff, null],
            'a few minutes on a week off is still a week off' => ['Week Off', '09:00', '10:00', 60, true, null, Resolution::WeekOff],

            // ---- who the person is outranks everything -------------------
            'unknown employee outranks everything' => ['FD', '10:10', '20:20', 480, false, Issue::UnknownEmployee, null],
            'unknown employee on a week off' => ['Week Off', null, null, 0, false, Issue::UnknownEmployee, null],
            'status case and spacing do not matter' => ['week off ', null, null, 0, true, null, Resolution::WeekOff],
        ];
    }

    #[DataProvider('rows')]
    public function test_classify(
        string $raw,
        ?string $firstIn,
        ?string $lastOut,
        int $workedMinutes,
        bool $known,
        ?Issue $issue,
        ?Resolution $resolution,
    ): void {
        $this->assertSame(
            ['issue' => $issue, 'resolution' => $resolution],
            AttendanceImportService::classify($raw, $firstIn, $lastOut, $workedMinutes, $known),
        );
    }

    /**
     * THE CALENDAR ANSWERS THE DAY NOBODY PUNCHED.
     *
     * 396 days of the August 2026 report land as "no punch" and wait for a
     * person, and 130 of those are Sundays. The report only knows a week
     * off when it says so itself; a public holiday it never mentions. Given
     * the calendar, a holiday nobody worked answers itself — and answers as
     * a HOLIDAY, which is neither leave nor an absence.
     *
     * A punch ON a holiday is simply the day worked: the clock decides it
     * like any other day and no issue is raised, because somebody who came
     * in on a holiday is present, not a query.
     */
    public function test_the_holiday_calendar_answers_a_day_nobody_punched(): void
    {
        $holiday = fn (string $raw, ?string $in, ?string $out, int $mins) => AttendanceImportService::classify($raw, $in, $out, $mins, true, isHoliday: true);

        $this->assertSame(
            ['issue' => null, 'resolution' => Resolution::Holiday],
            $holiday('Absent', null, null, 0),
            'a holiday nobody punched is a holiday, not an absence',
        );

        $this->assertSame(
            ['issue' => null, 'resolution' => Resolution::Present],
            $holiday('FD', '09:00', '18:00', 480),
            'a full shift worked on a holiday is present, and is not queried',
        );

        $this->assertSame(
            ['issue' => null, 'resolution' => Resolution::HalfDay],
            $holiday('HD', '10:00', '14:00', 240),
            'half a shift on a holiday is a half day',
        );

        $this->assertSame(
            ['issue' => null, 'resolution' => Resolution::WeekOff],
            $holiday('Week Off', null, null, 0),
            'a day the report itself calls a week off keeps that answer; the calendar only rescues days that would otherwise be asked about',
        );

        $this->assertSame(
            ['issue' => Issue::NoPunch, 'resolution' => null],
            AttendanceImportService::classify('Absent', null, null, 0, true),
            'and without the calendar the same day is still asked about',
        );
    }

    public function test_a_holiday_writes_nothing_to_attendances(): void
    {
        // The whole reason holiday follows week_off rather than becoming a
        // fifth AttendanceStatus: payroll counts days off that enum.
        $this->assertNull(Resolution::Holiday->attendanceStatus());
        $this->assertNull(Resolution::WeekOff->attendanceStatus());
        $this->assertSame('HO', Resolution::Holiday->sheetCode());
    }

    public function test_the_thresholds_are_the_owners_two_anchors(): void
    {
        // Eight hours is a full day and four hours is a half day
        // (DEC-20260903-005). FULL_DAY_MINUTES is one hour of tolerance
        // below the eight-hour shift, because a shift that clocks out at
        // 7h50m was worked.
        $this->assertSame(480, AttendanceImportService::SHIFT_MINUTES);
        $this->assertSame(420, AttendanceImportService::FULL_DAY_MINUTES);
        $this->assertSame(240, AttendanceImportService::HALF_DAY_MINUTES);
    }
}
