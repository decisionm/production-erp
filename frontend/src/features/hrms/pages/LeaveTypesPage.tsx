import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, Form, Input, InputNumber, Modal, Space, Switch, Table, Typography } from 'antd';
import { useMemo, useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { z } from 'zod';
import { createLeaveType, listLeaveTypes, updateLeaveType } from '@/features/hrms/api';
import { LEAVE_TYPE_DEFAULT_SORT, LEAVE_TYPE_LIST_SPEC, LEAVE_TYPE_SORT_FIELDS } from '@/features/hrms/list';
import type { LeaveType, LeaveTypeListParams } from '@/features/hrms/types';
import { compactParams } from '@/lib/listParams';
import { TABLE_STICKY, serverPagination } from '@/lib/tableProps';
import { columnSortOrder, sortParamFromSorter } from '@/lib/tableSort';
import { useListParams } from '@/lib/useListParams';

const leaveTypeSchema = z.object({
    code: z.string().min(1, 'Code is required').max(16),
    name: z.string().min(1, 'Name is required').max(255),
    default_annual_days: z.number().min(0),
});
type LeaveTypeFormValues = z.infer<typeof leaveTypeSchema>;

/**
 * THE LEAVE TYPE MASTER'S LIST. Sort, page and page size live in the URL
 * (useListParams) and the SERVER orders and pages (ListLeaveTypesRequest);
 * the pager is wired to the server's meta — this table drew the server's
 * first 20 with the pager off, so a 21st type existed and nothing on
 * screen said so.
 */
export default function LeaveTypesPage() {
    const [modalOpen, setModalOpen] = useState(false);
    const [editingLeaveType, setEditingLeaveType] = useState<LeaveType | null>(null);
    const queryClient = useQueryClient();

    const { params, setParams, setPage } = useListParams<LeaveTypeListParams>(LEAVE_TYPE_LIST_SPEC);
    const listParams = useMemo(() => compactParams(params), [params]);

    const { data, isFetching } = useQuery({
        // Still under the ['hrms', 'leave-types'] prefix every mutation invalidates.
        queryKey: ['hrms', 'leave-types', 'list', listParams],
        queryFn: () => listLeaveTypes(listParams),
        placeholderData: (previous) => previous,
    });

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
                sticky={TABLE_STICKY}
                scroll={{ x: 'max-content' }}
                rowKey="id"
                loading={isFetching}
                dataSource={data?.data}
                // SORTED BY THE SERVER: sortOrder-controlled, re-queried.
                onChange={(_pagination, _filters, sorter, extra) => {
                    if (extra.action !== 'sort') return;
                    setParams({ sort: sortParamFromSorter(sorter, LEAVE_TYPE_SORT_FIELDS, LEAVE_TYPE_DEFAULT_SORT) });
                }}
                pagination={serverPagination(data?.meta, setPage, 'leave types')}
                columns={[
                    {
                        title: 'Code',
                        dataIndex: 'code',
                        key: 'code',
                        sorter: true,
                        sortOrder: columnSortOrder('code', params.sort, LEAVE_TYPE_DEFAULT_SORT),
                    },
                    {
                        title: 'Name',
                        dataIndex: 'name',
                        key: 'name',
                        sorter: true,
                        sortOrder: columnSortOrder('name', params.sort, LEAVE_TYPE_DEFAULT_SORT),
                    },
                    {
                        title: 'Default Annual Days',
                        dataIndex: 'default_annual_days',
                        key: 'default_annual_days',
                        sorter: true,
                        sortOrder: columnSortOrder('default_annual_days', params.sort, LEAVE_TYPE_DEFAULT_SORT),
                    },
                    {
                        title: 'Active',
                        dataIndex: 'is_active',
                        key: 'is_active',
                        sorter: true,
                        sortOrder: columnSortOrder('is_active', params.sort, LEAVE_TYPE_DEFAULT_SORT),
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
                maskClosable={false}
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
                maskClosable={false}
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
