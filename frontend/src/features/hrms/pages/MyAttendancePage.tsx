import { Typography } from 'antd';
import { useState } from 'react';
import AttendanceRangeBar from '@/features/hrms/components/AttendanceRangeBar';
import MyAttendanceCard from '@/features/hrms/components/MyAttendanceCard';
import { rangeFor } from '@/features/hrms/attendanceRange';

/**
 * MY ATTENDANCE — the same card as the top of the HRMS Attendance page, on
 * a page of its own that needs no HRMS permission.
 *
 * The Attendance page is HRMS's, and the sidebar only offers it to people
 * with HRMS rights; a packer has neither. Their own month is still theirs,
 * so it gets a door of its own — same component, same read, no permission.
 */
export default function MyAttendancePage() {
    const [range, setRange] = useState(() => rangeFor('this_month'));

    return (
        <>
            <Typography.Title level={3} style={{ margin: '0 0 12px' }}>
                My Attendance
            </Typography.Title>

            <div style={{ marginBottom: 16 }}>
                <AttendanceRangeBar range={range} onChange={setRange} />
            </div>

            <MyAttendanceCard range={range} />
        </>
    );
}
