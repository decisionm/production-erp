import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, Form, InputNumber, Modal, Select, Space, Table, Typography } from 'antd';
import { useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { z } from 'zod';
import { allocateLeaveBalance, listEmployees, listLeaveBalances, listLeaveTypes } from '@/features/hrms/api';
import type { LeaveBalance } from '@/features/hrms/types';

const currentYear = new Date().getFullYear();

const allocateSchema = z.object({
    employee_id: z.number({ error: 'Employee is required' }),
    leave_type_id: z.number({ error: 'Leave type is required' }),
    year: z.number().min(2000).max(2100),
    allocated_days: z.number().min(0).optional(),
});
type AllocateFormValues = z.infer<typeof allocateSchema>;

export default function LeaveBalancesPage() {
    const [modalOpen, setModalOpen] = useState(false);
    const queryClient = useQueryClient();

    const { data, isLoading } = useQuery({ queryKey: ['hrms', 'leave-balances'], queryFn: listLeaveBalances });
    const { data: employees } = useQuery({ queryKey: ['hrms', 'employees'], queryFn: listEmployees });
    const { data: leaveTypes } = useQuery({ queryKey: ['hrms', 'leave-types'], queryFn: listLeaveTypes });

    const employeeOptions = employees?.data.map((e) => ({ value: e.id, label: `${e.employee_code} — ${e.name}` })) ?? [];
    const leaveTypeOptions = leaveTypes?.data.map((t) => ({ value: t.id, label: `${t.code} — ${t.name}` })) ?? [];

    const { control, handleSubmit, reset, formState: { errors } } = useForm<AllocateFormValues>({
        resolver: zodResolver(allocateSchema),
        defaultValues: { year: currentYear },
    });

    const mutation = useMutation({
        mutationFn: allocateLeaveBalance,
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['hrms', 'leave-balances'] });
            setModalOpen(false);
            reset({ year: currentYear });
        },
        onError: (error: any) => {
            Modal.error({ title: 'Could not allocate balance', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    return (
        <>
            <Space style={{ marginBottom: 16, justifyContent: 'space-between', width: '100%' }}>
                <Typography.Title level={3} style={{ margin: 0 }}>Leave Balances</Typography.Title>
                <Button type="primary" onClick={() => setModalOpen(true)}>Allocate Balance</Button>
            </Space>

            <Table<LeaveBalance>
                scroll={{ x: 'max-content' }}
                rowKey="id"
                loading={isLoading}
                dataSource={data?.data}
                pagination={false}
                columns={[
                    { title: 'Employee', render: (_, row) => row.employee?.name },
                    { title: 'Leave Type', render: (_, row) => row.leave_type.name },
                    { title: 'Year', dataIndex: 'year' },
                    { title: 'Allocated', dataIndex: 'allocated_days' },
                    { title: 'Used', dataIndex: 'used_days' },
                    { title: 'Remaining', dataIndex: 'remaining_days' },
                ]}
            />

            <Modal
                maskClosable={false}
                title="Allocate Leave Balance"
                open={modalOpen}
                onCancel={() => setModalOpen(false)}
                onOk={handleSubmit((values) => mutation.mutate(values))}
                confirmLoading={mutation.isPending}
                destroyOnHidden
            >
                <Form layout="vertical">
                    <Form.Item
                        label="Employee"
                        validateStatus={errors.employee_id ? 'error' : ''}
                        help={errors.employee_id?.message}
                    >
                        <Controller
                            name="employee_id"
                            control={control}
                            render={({ field }) => (
                                <Select {...field} options={employeeOptions} showSearch optionFilterProp="label" />
                            )}
                        />
                    </Form.Item>
                    <Form.Item
                        label="Leave Type"
                        validateStatus={errors.leave_type_id ? 'error' : ''}
                        help={errors.leave_type_id?.message}
                    >
                        <Controller
                            name="leave_type_id"
                            control={control}
                            render={({ field }) => (
                                <Select {...field} options={leaveTypeOptions} showSearch optionFilterProp="label" />
                            )}
                        />
                    </Form.Item>
                    <Form.Item label="Year" validateStatus={errors.year ? 'error' : ''} help={errors.year?.message}>
                        <Controller
                            name="year"
                            control={control}
                            render={({ field }) => <InputNumber {...field} style={{ width: '100%' }} />}
                        />
                    </Form.Item>
                    <Form.Item label="Allocated Days (leave blank to use the leave type's default)">
                        <Controller
                            name="allocated_days"
                            control={control}
                            render={({ field }) => <InputNumber {...field} min={0} style={{ width: '100%' }} />}
                        />
                    </Form.Item>
                </Form>
            </Modal>
        </>
    );
}
