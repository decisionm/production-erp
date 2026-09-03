import { PrinterOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import { Button, Card, Empty, Select, Space } from 'antd';
import { useMemo, useState } from 'react';
import { downloadAttendanceSheet, getAttendancePerson, listAllEmployees } from '@/features/hrms/api';
import type { DateRange } from '@/features/hrms/attendanceRange';
import { downloadBlob } from '@/lib/csv';
import { showApiError } from '@/lib/showApiError';
import PersonMonth from './PersonMonth';

/**
 * ANYBODY'S ATTENDANCE — pick a person, see their period.
 *
 * The card ABOVE this one is the reader's own month; this is the one HR and
 * the supervisors use, and they are always looking somebody ELSE up, which
 * is why it is a dropdown and why it needs the HRMS permission.
 *
 * It opens on the first name in the list rather than on an empty card, so a
 * page somebody opened to read attendance has attendance in it.
 */
export default function PersonAttendanceCard({ range }: { range: DateRange }) {
    const [chosen, setChosen] = useState<number | null>(null);

    const employees = useQuery({ queryKey: ['hrms', 'employees', 'all'], queryFn: listAllEmployees });

    const options = useMemo(
        () =>
            (employees.data?.data ?? [])
                .filter((employee) => employee.status === 'active')
                .map((employee) => ({ value: employee.id, label: `${employee.employee_code} — ${employee.name}` })),
        [employees.data],
    );

    const employeeId = chosen ?? options[0]?.value ?? null;

    const person = useQuery({
        queryKey: ['hrms', 'attendance', 'person', employeeId, range.from, range.to],
        queryFn: () => getAttendancePerson(employeeId as number, range.from, range.to),
        enabled: employeeId !== null,
    });
    const data = person.data;

    // The sheet is what the floor actually corrects on, so printing is a
    // button beside the person rather than something buried in Downloads.
    const [printing, setPrinting] = useState(false);
    const print = async () => {
        if (employeeId === null) return;
        setPrinting(true);
        try {
            const file = await downloadAttendanceSheet(employeeId, range.from, range.to);
            const code = data?.employee.employee_code ?? employeeId;
            downloadBlob(`attendance-${code}-${range.from}-to-${range.to}.pdf`, file.blob);
        } catch (error) {
            showApiError(error, 'Could not print the sheet');
        } finally {
            setPrinting(false);
        }
    };

    return (
        <Card
            title="One person"
            extra={
                <Space wrap>
                    <Button
                        icon={<PrinterOutlined />}
                        disabled={employeeId === null}
                        loading={printing}
                        onClick={print}
                    >
                        Print sheet
                    </Button>
                    <Select
                        showSearch
                        loading={employees.isFetching}
                        style={{ minWidth: 280 }}
                        placeholder="Pick an employee"
                        optionFilterProp="label"
                        value={employeeId ?? undefined}
                        options={options}
                        onChange={setChosen}
                    />
                </Space>
            }
        >
            {employeeId === null ? (
                <Empty
                    image={Empty.PRESENTED_IMAGE_SIMPLE}
                    description={employees.isFetching ? 'Loading employees…' : 'No active employees.'}
                />
            ) : (
                <PersonMonth
                    data={data ?? null}
                    loading={person.isFetching}
                    emptyText="Nothing recorded for this person in this period."
                />
            )}
        </Card>
    );
}
