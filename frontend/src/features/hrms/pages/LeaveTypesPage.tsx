import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, Form, Input, InputNumber, Modal, Space, Switch, Table, Typography } from 'antd';
import { useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { z } from 'zod';
import { createLeaveType, listLeaveTypes, updateLeaveType } from '@/features/hrms/api';
import type { LeaveType } from '@/features/hrms/types';

const leaveTypeSchema = z.object({
    code: z.string().min(1, 'Code is required').max(16),
    name: z.string().min(1, 'Name is required').max(255),
    default_annual_days: z.number().min(0),
});
type LeaveTypeFormValues = z.infer<typeof leaveTypeSchema>;

export default function LeaveTypesPage() {
    const [modalOpen, setModalOpen] = useState(false);
    const [editingLeaveType, setEditingLeaveType] = useState<LeaveType | null>(null);
    const queryClient = useQueryClient();

    const { data, isLoading } = useQuery({ queryKey: ['hrms', 'leave-types'], queryFn: listLeaveTypes });

    const invalidate = () => queryClient.invalidateQueries({ queryKey: ['hrms', 'leave-types'] });

    const { control, handleSubmit, reset, formState: { errors } } = useForm<LeaveTypeFormValues>({
        resolver: zodResolver(leaveTypeSchema),
        defaultValues: { code: '', name: '', default_annual_days: 0 },
    });

    const mutation = useMutation({
        mutationFn: createLeaveType,
        onSuccess: () => {
            invalidate();
            setModalOpen(false);
            reset();
        },
    });

    const {
        control: editControl,
        handleSubmit: handleEditSubmit,
        reset: resetEdit,
        formState: { errors: editErrors },
    } = useForm<LeaveTypeFormValues>({ resolver: zodResolver(leaveTypeSchema) });

    const editMutation = useMutation({
        mutationFn: ({ id, ...payload }: { id: number } & LeaveTypeFormValues) => updateLeaveType(id, payload),
        onSuccess: () => {
            invalidate();
            setEditingLeaveType(null);
        },
        onError: (error: any) => {
            Modal.error({ title: 'Could not update leave type', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    const activeMutation = useMutation({
        mutationFn: ({ id, is_active }: { id: number; is_active: boolean }) => updateLeaveType(id, { is_active }),
        onSuccess: invalidate,
    });

    return (
        <>
            <Space style={{ marginBottom: 16, justifyContent: 'space-between', width: '100%' }}>
                <Typography.Title level={3} style={{ margin: 0 }}>Leave Types</Typography.Title>
                <Button type="primary" onClick={() => setModalOpen(true)}>New Leave Type</Button>
            </Space>

            <Table<LeaveType>
                scroll={{ x: 'max-content' }}
                rowKey="id"
                loading={isLoading}
                dataSource={data?.data}
                pagination={false}
                columns={[
                    { title: 'Code', dataIndex: 'code' },
                    { title: 'Name', dataIndex: 'name' },
                    { title: 'Default Annual Days', dataIndex: 'default_annual_days' },
                    {
                        title: 'Active',
                        dataIndex: 'is_active',
                        render: (active: boolean, row) => (
                            <Switch
                                checked={active}
                                size="small"
                                loading={activeMutation.isPending}
                                onChange={(checked) => activeMutation.mutate({ id: row.id, is_active: checked })}
                            />
                        ),
                    },
                    {
                        title: 'Actions',
                        render: (_, row) => (
                            <Button
                                size="small"
                                onClick={() => {
                                    setEditingLeaveType(row);
                                    resetEdit({
                                        code: row.code,
                                        name: row.name,
                                        default_annual_days: Number(row.default_annual_days),
                                    });
                                }}
                            >
                                Edit
                            </Button>
                        ),
                    },
                ]}
            />

            <Modal
                title="New Leave Type"
                open={modalOpen}
                onCancel={() => setModalOpen(false)}
                onOk={handleSubmit((values) => mutation.mutate(values))}
                confirmLoading={mutation.isPending}
                destroyOnHidden
            >
                <Form layout="vertical">
                    <Form.Item label="Code" validateStatus={errors.code ? 'error' : ''} help={errors.code?.message}>
                        <Controller name="code" control={control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item label="Name" validateStatus={errors.name ? 'error' : ''} help={errors.name?.message}>
                        <Controller name="name" control={control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item label="Default Annual Days">
                        <Controller
                            name="default_annual_days"
                            control={control}
                            render={({ field }) => <InputNumber {...field} min={0} style={{ width: '100%' }} />}
                        />
                    </Form.Item>
                </Form>
            </Modal>

            <Modal
                title={`Edit "${editingLeaveType?.name}"`}
                open={editingLeaveType !== null}
                onCancel={() => setEditingLeaveType(null)}
                onOk={handleEditSubmit((values) => {
                    if (editingLeaveType) editMutation.mutate({ id: editingLeaveType.id, ...values });
                })}
                confirmLoading={editMutation.isPending}
                destroyOnHidden
            >
                <Form layout="vertical">
                    <Form.Item label="Code" validateStatus={editErrors.code ? 'error' : ''} help={editErrors.code?.message}>
                        <Controller name="code" control={editControl} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item label="Name" validateStatus={editErrors.name ? 'error' : ''} help={editErrors.name?.message}>
                        <Controller name="name" control={editControl} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item label="Default Annual Days">
                        <Controller
                            name="default_annual_days"
                            control={editControl}
                            render={({ field }) => <InputNumber {...field} min={0} style={{ width: '100%' }} />}
                        />
                    </Form.Item>
                </Form>
            </Modal>
        </>
    );
}
