import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, Form, InputNumber, Modal, Space, Table, Tag, Typography } from 'antd';
import { useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { useNavigate } from 'react-router-dom';
import { z } from 'zod';
import { createPayrollRun, listPayrollRuns, markPayrollRunPaid, processPayrollRun } from '@/features/payroll/api';
import type { PayrollRun, PayrollRunStatus } from '@/features/payroll/types';

const currentYear = new Date().getFullYear();

const runSchema = z.object({
    year: z.number().min(2000).max(2100),
    month: z.number().min(1).max(12),
});
type RunFormValues = z.infer<typeof runSchema>;

const statusColor: Record<PayrollRunStatus, string> = {
    draft: 'default',
    processed: 'blue',
    paid: 'green',
};

const monthNames = [
    'January', 'February', 'March', 'April', 'May', 'June',
    'July', 'August', 'September', 'October', 'November', 'December',
];

export default function PayrollRunsPage() {
    const [modalOpen, setModalOpen] = useState(false);
    const queryClient = useQueryClient();
    const navigate = useNavigate();

    const { data, isLoading } = useQuery({ queryKey: ['payroll', 'runs'], queryFn: listPayrollRuns });

    const { control, handleSubmit, reset, formState: { errors } } = useForm<RunFormValues>({
        resolver: zodResolver(runSchema),
        defaultValues: { year: currentYear, month: new Date().getMonth() + 1 },
    });

    const invalidate = () => queryClient.invalidateQueries({ queryKey: ['payroll', 'runs'] });

    const createMutation = useMutation({
        mutationFn: createPayrollRun,
        onSuccess: () => {
            invalidate();
            setModalOpen(false);
            reset({ year: currentYear, month: new Date().getMonth() + 1 });
        },
        onError: (error: any) => {
            Modal.error({ title: 'Could not create payroll run', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    const processMutation = useMutation({
        mutationFn: processPayrollRun,
        onSuccess: ({ skipped }) => {
            invalidate();
            if (skipped.length > 0) {
                Modal.warning({
                    title: 'Payroll processed with some employees skipped',
                    content: (
                        <div>
                            <p>These active employees have no salary structure yet and were not paid this run:</p>
                            <ul>
                                {skipped.map((s) => <li key={s.employee_id}>{s.employee_name}</li>)}
                            </ul>
                        </div>
                    ),
                });
            }
        },
        onError: (error: any) => {
            Modal.error({ title: 'Could not process payroll run', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    const markPaidMutation = useMutation({
        mutationFn: markPayrollRunPaid,
        onSuccess: invalidate,
        onError: (error: any) => {
            Modal.error({ title: 'Could not mark payroll run as paid', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    return (
        <>
            <Space style={{ marginBottom: 16, justifyContent: 'space-between', width: '100%' }}>
                <Typography.Title level={3} style={{ margin: 0 }}>Payroll Runs</Typography.Title>
                <Button type="primary" onClick={() => setModalOpen(true)}>New Payroll Run</Button>
            </Space>

            <Table<PayrollRun>
                rowKey="id"
                loading={isLoading}
                dataSource={data?.data}
                pagination={false}
                columns={[
                    { title: 'Period', render: (_, row) => `${monthNames[row.month - 1]} ${row.year}` },
                    {
                        title: 'Status',
                        dataIndex: 'status',
                        render: (status: PayrollRunStatus) => <Tag color={statusColor[status]}>{status}</Tag>,
                    },
                    { title: 'Processed At', render: (_, row) => row.processed_at ?? '—' },
                    { title: 'Paid At', render: (_, row) => row.paid_at ?? '—' },
                    {
                        title: 'Actions',
                        render: (_, row) => (
                            <Space>
                                {row.status === 'draft' && (
                                    <Button
                                        size="small"
                                        onClick={() => processMutation.mutate(row.id)}
                                        loading={processMutation.isPending}
                                    >
                                        Process
                                    </Button>
                                )}
                                {row.status === 'processed' && (
                                    <Button
                                        size="small"
                                        onClick={() => markPaidMutation.mutate(row.id)}
                                        loading={markPaidMutation.isPending}
                                    >
                                        Mark Paid
                                    </Button>
                                )}
                                {row.status !== 'draft' && (
                                    <Button size="small" onClick={() => navigate(`/payroll/payslips?payroll_run_id=${row.id}`)}>
                                        View Payslips
                                    </Button>
                                )}
                            </Space>
                        ),
                    },
                ]}
            />

            <Modal
                title="New Payroll Run"
                open={modalOpen}
                onCancel={() => setModalOpen(false)}
                onOk={handleSubmit((values) => createMutation.mutate(values))}
                confirmLoading={createMutation.isPending}
                destroyOnHidden
            >
                <Form layout="vertical">
                    <Form.Item label="Year" validateStatus={errors.year ? 'error' : ''} help={errors.year?.message}>
                        <Controller
                            name="year"
                            control={control}
                            render={({ field }) => <InputNumber {...field} style={{ width: '100%' }} />}
                        />
                    </Form.Item>
                    <Form.Item label="Month" validateStatus={errors.month ? 'error' : ''} help={errors.month?.message}>
                        <Controller
                            name="month"
                            control={control}
                            render={({ field }) => <InputNumber {...field} min={1} max={12} style={{ width: '100%' }} />}
                        />
                    </Form.Item>
                </Form>
            </Modal>
        </>
    );
}
