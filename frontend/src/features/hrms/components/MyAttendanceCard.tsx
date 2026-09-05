import { useQuery } from '@tanstack/react-query';
import { Card, Empty, Space, Statistic } from 'antd';
import { getMyAttendance } from '@/features/hrms/api';
import type { DateRange } from '@/features/hrms/attendanceRange';
import PersonMonth from './PersonMonth';

/**
 * MY OWN ATTENDANCE — the first thing on the page, for whoever is looking.
 *
 * It asks a read that takes no employee: the server answers for whoever is
 * logged in, so this card can be shown to anybody with a login without
 * showing anybody else's month. That is also why it sits outside the HRMS
 * permission — your own attendance is yours, and a person has a right to
 * see it before payroll is run off it.
 *
 * A LOGIN WITH NO EMPLOYEE ROW GETS A BLANK CARD, deliberately. Most of the
 * factory's staff have no login yet, and matching a user to an employee by
 * name would be the one guess that puts somebody else's month on your
 * screen. It fills itself in as logins are linked to employee rows.
 *
 * The leave figures ride on the same read for the same reason: how much
 * casual leave you have left is yours to know, and asking the leave-balance
 * list for it would need the HRMS permission a packer does not have. A year
 * with nothing allocated shows no strip at all rather than a row of zeroes.
 */
export default function MyAttendanceCard({ range }: { range: DateRange }) {
    const mine = useQuery({
        queryKey: ['hrms', 'attendance', 'me', range.from, range.to],
        queryFn: () => getMyAttendance(range.from, range.to),
    });
    const data = mine.data;

    return (
        <Card title="My attendance">
            {data && data.employee === null ? (
                <Empty
                    image={Empty.PRESENTED_IMAGE_SIMPLE}
                    description="This login is not linked to an employee record."
                />
            ) : (
                <>
                    {data && data.leave_balances.length > 0 ? (
                        <Space size="large" wrap style={{ marginBottom: 16 }}>
                            {data.leave_balances.map((balance) => (
                                <Statistic
                                    key={balance.code}
                                    title={`${balance.name} left`}
                                    value={balance.remaining_days}
                                    // Shown exactly as the server sends it. Statistic
                                    // otherwise splits and regroups the number, which
                                    // turns a half day into its own decimal span.
                                    formatter={(value) => String(value)}
                                    valueStyle={{ fontSize: 20 }}
                                />
                            ))}
                        </Space>
                    ) : null}
                    <PersonMonth
                        data={data?.employee ? { ...data, employee: data.employee } : null}
                        loading={mine.isFetching}
                        emptyText="Nothing recorded for you in this period."
                    />
                </>
            )}
        </Card>
    );
}
