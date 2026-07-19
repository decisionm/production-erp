import { useQuery } from '@tanstack/react-query';
import { Select, Space, Table, Tag, Typography } from 'antd';
import { useSearchParams } from 'react-router-dom';
import { listPayrollRuns, listPayslips } from '@/features/payroll/api';
import type { Payslip, PayslipLineType } from '@/features/payroll/types';

const monthNames = [
    'January', 'February', 'March', 'April', 'May', 'June',
    'July', 'August', 'September', 'October', 'November', 'December',
];

const lineTypeColor: Record<PayslipLineType, string> = {
    earning: 'green',
    deduction: 'red',
    employer_contribution: 'blue',
};

export default function PayslipsPage() {
    const [searchParams, setSearchParams] = useSearchParams();
    const payrollRunId = searchParams.get('payroll_run_id');
    const payrollRunIdNumber = payrollRunId ? Number(payrollRunId) : undefined;

    const { data: runs } = useQuery({ queryKey: ['payroll', 'runs'], queryFn: listPayrollRuns });
    const runOptions = [
        { value: undefined, label: 'All runs' },
        ...(runs?.data.map((r) => ({ value: r.id, label: `${monthNames[r.month - 1]} ${r.year}` })) ?? []),
    ];

    const { data, isLoading } = useQuery({
        queryKey: ['payroll', 'payslips', payrollRunIdNumber],
        queryFn: () => listPayslips(payrollRunIdNumber),
    });

    return (
        <>
            <Space style={{ marginBottom: 16, justifyContent: 'space-between', width: '100%' }}>
                <Typography.Title level={3} style={{ margin: 0 }}>Payslips</Typography.Title>
                <Select
                    style={{ width: 220 }}
                    value={payrollRunIdNumber}
                    options={runOptions}
                    onChange={(value) => setSearchParams(value ? { payroll_run_id: String(value) } : {})}
                />
            </Space>

            <Table<Payslip>
                rowKey="id"
                loading={isLoading}
                dataSource={data?.data}
                pagination={false}
                columns={[
                    { title: 'Employee', render: (_, row) => row.employee?.name },
                    { title: 'Gross Earnings', dataIndex: 'gross_earnings' },
                    { title: 'Total Deductions', dataIndex: 'total_deductions' },
                    { title: 'Net Pay', dataIndex: 'net_pay' },
                ]}
                expandable={{
                    expandedRowRender: (row) => (
                        <Table
                            rowKey="id"
                            size="small"
                            dataSource={row.lines}
                            pagination={false}
                            columns={[
                                { title: 'Label', dataIndex: 'label' },
                                {
                                    title: 'Type',
                                    dataIndex: 'type',
                                    render: (type: PayslipLineType) => <Tag color={lineTypeColor[type]}>{type}</Tag>,
                                },
                                { title: 'Amount', dataIndex: 'amount' },
                            ]}
                        />
                    ),
                }}
            />
        </>
    );
}
