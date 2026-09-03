<?php

namespace Tests\Unit\HRMS;

use App\Modules\HRMS\Models\Enums\AttendanceImportIssue as Issue;
use App\Modules\HRMS\Models\Enums\AttendanceImportResolution as Resolution;
use App\Modules\HRMS\Services\AttendanceImportService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The issue table of the 03-Sep design, pinned row by row. A line either
 * resolves itself (present / half_day / week_off) or names why a person
 * has to look at it; nothing here writes anything.
 */
class AttendanceImportClassifyTest extends TestCase
{
    /** @return array<string, array{0: string, 1: ?string, 2: ?string, 3: bool, 4: ?Issue, 5: ?Resolution}> */
    public static function rows(): array
    {
        return [
            'full day, both punches' => ['FD', '10:10', '20:20', true, null, Resolution::Present],
            'half day, both punches' => ['HD', '10:10', '14:05', true, null, Resolution::HalfDay],
            'week off, no punches' => ['Week Off', null, null, true, null, Resolution::WeekOff],
            'week off with punches is still a week off' => ['Week Off', '09:00', '18:00', true, null, Resolution::WeekOff],
            'in without out' => ['FD', '10:10', null, true, Issue::InNoOut, null],
            'half day in without out' => ['HD', '10:10', null, true, Issue::InNoOut, null],
            'out without in' => ['FD', null, '20:20', true, Issue::OutNoIn, null],
            'absent, no punches' => ['Absent', null, null, true, Issue::NoPunch, null],
            'dash status, no punches' => ['-', null, null, true, Issue::NoPunch, null],
            'unrecognised status, no punches' => ['Holiday', null, null, true, Issue::NoPunch, null],
            'unrecognised status, both punches' => ['Holiday', '09:00', '18:00', true, null, Resolution::Present],
            'unknown employee outranks everything' => ['FD', '10:10', '20:20', false, Issue::UnknownEmployee, null],
            'unknown employee on a week off' => ['Week Off', null, null, false, Issue::UnknownEmployee, null],
            'status case and spacing do not matter' => ['week off ', null, null, true, null, Resolution::WeekOff],
        ];
    }

    #[DataProvider('rows')]
    public function test_classify(string $raw, ?string $firstIn, ?string $lastOut, bool $known, ?Issue $issue, ?Resolution $resolution): void
    {
        $this->assertSame(
            ['issue' => $issue, 'resolution' => $resolution],
            AttendanceImportService::classify($raw, $firstIn, $lastOut, $known),
        );
    }
}
