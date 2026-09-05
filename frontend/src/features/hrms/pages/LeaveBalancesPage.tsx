import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, Form, InputNumber, Modal, Select, Space, Table, Typography } from 'antd';
import { useMemo, useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { z } from 'zod';
import { activePickerOptions } from '@/components/configuration/pickerOptions';
import { allocateLeaveBalance, listAllEmployees, listAllLeaveTypes, listLeaveBalances } from '@/features/hrms/api';
import { LEAVE_BALANCE_DEFAULT_SORT, LEAVE_BALANCE_LIST_SPEC, LEAVE_BALANCE_SORT_FIELDS } from '@/features/hrms/list';
import type { LeaveBalance, LeaveBalanceListParams } from '@/features/hrms/types';
import { compactParams } from '@/lib/listParams';
import { TABLE_STICKY, serverPagination } from '@/lib/tableProps';
import { columnSortOrder, sortParamFromSorter } from '@/lib/tableSort';
import { useListParams } from '@/lib/useListParams';

const currentYear = new Date().getFullYear();

const allocateSchema = z.object({
    employee_id: z.number({ error: 'Employee is required' }),
    leave_type_id: z.number({ error: 'Leave type is required' }),
    year: z.number().min(2000).max(2100),
    allocated_days: z.number().min(0).optional(),
    // Carried in from before the ERP held the figure. Part OF the
    // allocation, never on top of it — the server refuses one that
    // exceeds an allocation given explicitly.
    opening_days: z.number().min(0).optional(),
});
type AllocateFormValues = z.infer<typeof allocateSchema>;

/**
 * THE LEAVE BALANCE LIST. Sort, page and page size live in the URL
 * (useListParams) and the SERVER orders and pages (ListLeaveBalancesRequest);
 * the pager is wired to the server's meta — this table drew the server's
 * first 20 with the pager off, so the 21st balance existed and nothing on
 * screen said so.
 */
export default function LeaveBalancesPage() {
    const [modalOpen, setModalOpen] = useState(false);
    const queryClient = useQueryClient();

    const { params, setParams, setPage } = useListParams<LeaveBalanceListParams>(LEAVE_BALANCE_LIST_SPEC);
    const listParams = useMemo(() => compactParams(params), [params]);

    const { data, isFetching } = useQuery({
        // Still under the ['hrms', 'leave-balances'] prefix every mutation invalidates.
        queryKey: ['hrms', 'leave-balances', 'list', listParams],
        queryFn: () => listLeaveBalances(listParams),
        placeholderData: (previous) => previous,
    });
    const { data: employees } = useQuery({ queryKey: ['hrms', 'employees', 'all'], queryFn: listAllEmployees });
    const { data: leaveTypes } = useQuery({ queryKey: ['hrms', 'leave-types', 'all'], queryFn: listAllLeaveTypes });

    const employeeOptions = employees?.data.map((e) => ({ value: e.id, label: `${e.employee_code} — ${e.name}` })) ?? [];
    // WS-B: a WITHDRAWN leave type is refused by the server, so it is no
    // longer offered here. Leave already taken under it still reads back.
    const leaveTypeOptions = activePickerOptions(leaveTypes?.data, {
        isActive: (t) => t.is_active,
        option: (t) => ({ value: t.id, label: `${t.code} — ${t.name}` }),
    });

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
                sticky={TABLE_STICKY}
                scroll={{ x: 'max-content' }}
                rowKey="id"
                loading={isFetching}
                dataSource={data?.data}
                // SORTED BY THE SERVER: sortOrder-controlled, re-queried.
                onChange={(_pagination, _filters, sorter, extra) => {
                    if (extra.action !== 'sort') return;
                    setParams({ sort: sortParamFromSorter(sorter, LEAVE_BALANCE_SORT_FIELDS, LEAVE_BALANCE_DEFAULT_SORT) });
                }}
                pagination={serverPagination(data?.meta, setPage, 'leave balances')}
                columns={[
                    // Names through relations, not columns of this table: no server sort.
                    { title: 'Employee', render: (_, row) => row.employee?.name },
                    { title: 'Leave Type', render: (_, row) => row.leave_type.name },
                    {
                        title: 'Year',
                        dataIndex: 'year',
                        key: 'year',
                        sorter: true,
                        sortOrder: columnSortOrder('year', params.sort, LEAVE_BALANCE_DEFAULT_SORT),
                    },
                    {
                        title: 'Opening',
                        dataIndex: 'opening_days',
                        key: 'opening_days',
                        sorter: true,
                        sortOrder: columnSortOrder('opening_days', params.sort, LEAVE_BALANCE_DEFAULT_SORT),
                        // Nothing carried in is a dash: a 0.00 among real figures
                        // reads as a balance rather than an absence.
                        render: (_, row) => (Number(row.opening_days) === 0 ? '—' : row.opening_days),
                    },
                    {
                        title: 'Allocated',
                        dataIndex: 'allocated_days',
                        key: 'allocated_days',
                        sorter: true,
                        sortOrder: columnSortOrder('allocated_days', params.sort, LEAVE_BALANCE_DEFAULT_SORT),
                    },
                    {
                        title: 'Used',
                        dataIndex: 'used_days',
                        key: 'used_days',
                        sorter: true,
                        sortOrder: columnSortOrder('used_days', params.sort, LEAVE_BALANCE_DEFAULT_SORT),
                    },
                    // Both computed in the resource, not stored: no server sort.
                    { title: 'Accrued', dataIndex: 'accrued_days' },
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
                    <Form.Item label="Opening Balance (carried in)" validateStatus={errors.opening_days ? 'error' : ''} help={errors.opening_days?.message}>
                        <Controller
                            name="opening_days"
                            control={control}
                            render={({ field }) => <InputNumber {...field} min={0} step={0.5} style={{ width: '100%' }} />}
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
