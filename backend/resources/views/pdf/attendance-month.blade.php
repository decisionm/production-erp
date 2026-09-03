@php
    /**
     * ONE PERSON'S MONTH, ON ONE PAGE.
     *
     * Printed, handed over, and written on: the floor already corrects
     * attendance on paper, so the sheet leaves room for it rather than
     * pretending the correction will arrive some other way.
     */
    $labels = ['present' => 'Present', 'absent' => 'Absent', 'half_day' => 'Half Day', 'on_leave' => 'On Leave'];
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Attendance — {{ $employee['employee_code'] }} — {{ $from_label }} to {{ $to_label }}</title>
    <style>
        @page { margin: 18mm 14mm; }
        body { font-family: sans-serif; font-size: 11px; color: #1f1f1f; }
        h1 { font-size: 18px; margin: 0 0 2px; }
        .muted { color: #666; }
        table.header { width: 100%; margin-bottom: 14px; }
        table.header td { vertical-align: top; }
        table.header td.meta { text-align: right; width: 45%; }
        .who { font-size: 14px; font-weight: bold; margin-bottom: 2px; }

        table.totals { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        table.totals td { border: 1px solid #ddd; padding: 6px 8px; text-align: center; width: 20%; }
        table.totals .n { font-size: 17px; font-weight: bold; display: block; }
        table.totals .k { font-size: 9px; text-transform: uppercase; color: #666; letter-spacing: .04em; }

        table.days { width: 100%; border-collapse: collapse; }
        table.days th, table.days td { border: 1px solid #ddd; padding: 3px 6px; text-align: left; }
        table.days th { background: #f5f5f5; font-size: 9px; text-transform: uppercase; color: #555; letter-spacing: .04em; }
        table.days td.day { width: 58px; white-space: nowrap; }
        table.days td.time { width: 46px; text-align: center; }
        table.days tr.sunday td { background: #fafafa; }
        table.days td.gap { color: #999; font-style: italic; }

        .sign { margin-top: 18px; width: 100%; }
        .sign td { padding-top: 26px; font-size: 10px; color: #555; }
        .rule { border-bottom: 1px solid #999; height: 1px; margin-bottom: 3px; }
        .footer { margin-top: 14px; font-size: 9px; color: #999; }
    </style>
</head>
<body>
    <table class="header">
        <tr>
            <td>
                <h1>{{ config('company.name') }}</h1>
                <div class="muted">Attendance sheet</div>
            </td>
            <td class="meta">
                <div class="who">{{ $employee['employee_code'] }} — {{ $employee['name'] }}</div>
                <div class="muted">{{ collect([$employee['department'], $employee['designation']])->filter()->implode(' · ') }}</div>
                <div class="muted">{{ $from_label }} to {{ $to_label }}</div>
            </td>
        </tr>
    </table>

    <table class="totals">
        <tr>
            <td><span class="n">{{ $summary['present'] }}</span><span class="k">Present</span></td>
            <td><span class="n">{{ $summary['half_day'] }}</span><span class="k">Half Day</span></td>
            <td><span class="n">{{ $summary['absent'] }}</span><span class="k">Absent</span></td>
            <td><span class="n">{{ $summary['on_leave'] }}</span><span class="k">On Leave</span></td>
            <td><span class="n">{{ $summary['recorded'] }}</span><span class="k">Days recorded</span></td>
        </tr>
    </table>

    <table class="days">
        <thead>
            <tr>
                <th>Day</th>
                <th>Counts as</th>
                <th class="time">In</th>
                <th class="time">Out</th>
                <th>Note</th>
            </tr>
        </thead>
        <tbody>
            @foreach($days as $day)
                <tr class="day {{ $day['is_sunday'] ? 'sunday' : '' }}">
                    <td class="day">{{ $day['label'] }}</td>
                    @if($day['status'] === null)
                        {{-- Not recorded is NOT absent. Saying so on a sheet
                             somebody is paid against would assert a fact
                             nobody entered. --}}
                        <td class="gap" colspan="4">not recorded</td>
                    @else
                        <td>{{ $labels[$day['status']] ?? $day['status'] }}</td>
                        <td class="time">{{ $day['check_in'] ?? '—' }}</td>
                        <td class="time">{{ $day['check_out'] ?? '—' }}</td>
                        <td>{{ $day['notes'] }}</td>
                    @endif
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="sign">
        <tr>
            <td style="width: 55%;">
                <div class="rule"></div>
                Corrections (write the day and what it should be)
            </td>
            <td style="width: 10%;"></td>
            <td style="width: 35%;">
                <div class="rule"></div>
                Signature
            </td>
        </tr>
    </table>

    <div class="footer">
        Printed {{ $printed_at }} · times are IST · a day left blank is one nobody recorded, not an absence.
    </div>
</body>
</html>
