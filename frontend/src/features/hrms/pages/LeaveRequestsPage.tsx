import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, DatePicker, Descriptions, Drawer, Form, Input, InputNumber, Modal, Select, Space, Table, Tag, Typography } from 'antd';
import { useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { z } from 'zod';
import {
    approveLeaveRequest,
    createLeaveRequest,
    listAllEmployees,
    listLeaveRequests,
    listLeaveTypes,
    rejectLeaveRequest,
} from '@/features/hrms/api';
import type { LeaveRequest, LeaveRequestStatus } from '@/features/hrms/types';

const requestSchema = z.object({
    employee_id: z.number({ error: 'Employee is required' }),
    leave_type_id: z.number({ error: 'Leave type is required' }),
    start_date: z.string({ error: 'Start date is required' }),
    end_date: z.string({ error: 'End date is required' }),
    days: z.number().gt(0, 'Days must be greater than 0'),
    reason: z.string().optional(),
});
type RequestFormValues = z.infer<typeof requestSchema>;

const statusColor: Record<LeaveRequestStatus, string> = {
    pending: 'blue',
    approved: 'green',
    rejected: 'red',
};

export default function LeaveRequestsPage() {
    const [modalOpen, setModalOpen] = useState(false);
    const [detailRow, setDetailRow] = useState<LeaveRequest | null>(null);
    const queryClient = useQueryClient();

    const { data, isLoading } = useQuery({ queryKey: ['hrms', 'leave-requests'], queryFn: listLeaveRequests });
    const { data: employees } = useQuery({ queryKey: ['hrms', 'employees', 'all'], queryFn: listAllEmployees });
    const { data: leaveTypes } = useQuery({ queryKey: ['hrms', 'leave-types'], queryFn: listLeaveTypes });

    const employeeOptions = employees?.data.map((e) => ({ value: e.id, label: `${e.employee_code} — ${e.name}` })) ?? [];
    const leaveTypeOptions = leaveTypes?.data.map((t) => ({ value: t.id, label: `${t.code} — ${t.name}` })) ?? [];

    const { control, handleSubmit, reset, formState: { errors } } = useForm<RequestFormValues>({
        resolver: zodResolver(requestSchema),
    });

    const invalidate = () => {
        queryClient.invalidateQueries({ queryKey: ['hrms', 'leave-requests'] });
        queryClient.invalidateQueries({ queryKey: ['hrms', 'leave-balances'] });
    };

    const createMutation = useMutation({
        mutationFn: createLeaveRequest,
        onSuccess: () => {
            invalidate();
            setModalOpen(false);
            reset();
        },
        onError: (error: any) => {
            Modal.error({ title: 'Could not submit leave request', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    const approveMutation = useMutation({
        mutationFn: approveLeaveRequest,
        onSuccess: invalidate,
        onError: (error: any) => {
            Modal.error({ title: 'Could not approve', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });
    const rejectMutation = useMutation({ mutationFn: rejectLeaveRequest, onSuccess: invalidate });

    return (
        <>
            <Space style={{ marginBottom: 16, justifyContent: 'space-between', width: '100%' }}>
                <Typography.Title level={3} style={{ margin: 0 }}>Leave Requests</Typography.Title>
                <Button type="primary" onClick={() => setModalOpen(true)}>New Leave Request</Button>
            </Space>

            <Table<LeaveRequest>
                scroll={{ x: 'max-content' }}
                rowKey="id"
                loading={isLoading}
                dataSource={data?.data}
                pagination={false}
                columns={[
                    { title: 'Employee', render: (_, row) => row.employee?.name },
                    { title: 'Leave Type', render: (_, row) => row.leave_type.name },
                    { title: 'Start', dataIndex: 'start_date' },
                    { title: 'End', dataIndex: 'end_date' },
                    { title: 'Days', dataIndex: 'days' },
                    {
                        title: 'Status',
                        dataIndex: 'status',
                        render: (status: LeaveRequestStatus) => <Tag color={statusColor[status]}>{status}</Tag>,
                    },
                    {
                        title: 'Actions',
                        render: (_, row) => (
                            <Space>
                                <Button size="small" onClick={() => setDetailRow(row)}>View</Button>
                                {row.status === 'pending' && (
                                    <>
                                        <Button
                                            size="small"
                                            onClick={() => approveMutation.mutate(row.id)}
                                            loading={approveMutation.isPending}
                                        >
                                            Approve
                                        </Button>
                                        <Button
                                            size="small"
                                            danger
                                            onClick={() => rejectMutation.mutate(row.id)}
                                            loading={rejectMutation.isPending}
                                        >
                                            Reject
                                        </Button>
                                    </>
                                )}
                            </Space>
                        ),
                    },
                ]}
            />

            <Modal
                maskClosable={false}
                title="New Leave Request"
                open={modalOpen}
                onCancel={() => setModalOpen(false)}
                onOk={handleSubmit((values) => createMutation.mutate(values))}
                confirmLoading={createMutation.isPending}
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
                    <Form.Item
                        label="Start Date"
                        validateStatus={errors.start_date ? 'error' : ''}
                        help={errors.start_date?.message}
                    >
                        <Controller
                            name="start_date"
                            control={control}
                            render={({ field }) => (
                                <DatePicker
                                    style={{ width: '100%' }}
                                    onChange={(_, dateString) => field.onChange(dateString || undefined)}
                                />
                            )}
                        />
                    </Form.Item>
                    <Form.Item label="End Date" validateStatus={errors.end_date ? 'error' : ''} help={errors.end_date?.message}>
                        <Controller
                            name="end_date"
                            control={control}
                            render={({ field }) => (
                                <DatePicker
                                    style={{ width: '100%' }}
                                    onChange={(_, dateString) => field.onChange(dateString || undefined)}
                                />
                            )}
                        />
                    </Form.Item>
                    <Form.Item label="Days" validateStatus={errors.days ? 'error' : ''} help={errors.days?.message}>
                        <Controller
                            name="days"
                            control={control}
                            render={({ field }) => <InputNumber {...field} min={0} style={{ width: '100%' }} />}
                        />
                    </Form.Item>
                    <Form.Item label="Reason">
                        <Controller name="reason" control={control} render={({ field }) => <Input.TextArea {...field} rows={2} />} />
                    </Form.Item>
                </Form>
            </Modal>

            <Drawer
                title={`Leave Request #${detailRow?.id}`}
                open={detailRow !== null}
                onClose={() => setDetailRow(null)}
                width="min(100vw, 480px)"
                destroyOnHidden
            >
                {detailRow && (
                    <Descriptions column={1} size="small" bordered>
                        <Descriptions.Item label="Employee">{detailRow.employee?.name ?? '—'}</Descriptions.Item>
                        <Descriptions.Item label="Leave Type">{detailRow.leave_type.name}</Descriptions.Item>
                        <Descriptions.Item label="Status">
                            <Tag color={statusColor[detailRow.status]}>{detailRow.status}</Tag>
                        </Descriptions.Item>
                        <Descriptions.Item label="Start Date">{detailRow.start_date}</Descriptions.Item>
                        <Descriptions.Item label="End Date">{detailRow.end_date}</Descriptions.Item>
                        <Descriptions.Item label="Days">{detailRow.days}</Descriptions.Item>
                        <Descriptions.Item label="Reason">{detailRow.reason ?? '—'}</Descriptions.Item>
                        <Descriptions.Item label="Approved By">{detailRow.approved_by ?? '—'}</Descriptions.Item>
                        <Descriptions.Item label="Decided At">{detailRow.decided_at ?? '—'}</Descriptions.Item>
                    </Descriptions>
                )}
            </Drawer>
        </>
    );
}
