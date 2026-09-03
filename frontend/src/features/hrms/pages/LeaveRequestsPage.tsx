import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, DatePicker, Descriptions, Drawer, Empty, Form, Input, InputNumber, Modal, Select, Space, Table, Tag, Typography } from 'antd';
import { useEffect, useMemo, useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { z } from 'zod';
import { activePickerOptions } from '@/components/configuration/pickerOptions';
import {
    approveLeaveRequest,
    createLeaveRequest,
    listAllEmployees,
    listAllLeaveTypes,
    listLeaveRequests,
    rejectLeaveRequest,
} from '@/features/hrms/api';
import {
    LEAVE_REQUEST_DEFAULT_SORT,
    LEAVE_REQUEST_LIST_SPEC,
    LEAVE_REQUEST_SORT_FIELDS,
    noMatchLine,
    pageRangeLine,
} from '@/features/hrms/list';
import type { LeaveRequest, LeaveRequestListParams, LeaveRequestStatus } from '@/features/hrms/types';
import { ListEmpty, ListReadAlert } from '@/lib/ListEmpty';
import { compactParams, narrowingKeys } from '@/lib/listParams';
import { TABLE_STICKY, serverPagination } from '@/lib/tableProps';
import { columnSortOrder, sortParamFromSorter } from '@/lib/tableSort';
import { useListParams } from '@/lib/useListParams';

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

const STATUS_FILTER: { value: LeaveRequestStatus | ''; label: string }[] = [
    { value: '', label: 'All statuses' },
    { value: 'pending', label: 'Pending' },
    { value: 'approved', label: 'Approved' },
    { value: 'rejected', label: 'Rejected' },
];

/**
 * THE LEAVE REQUEST LIST. Search, status, employee, page and page size
 * live in the URL (useListParams) and the SERVER does the narrowing
 * (ListLeaveRequestsRequest): `q` goes THROUGH the employee — code, name,
 * department, designation — because a leave request has no number anyone
 * types. The pager is wired to the server's meta, so a request older than
 * the newest screen is reachable.
 */
export default function LeaveRequestsPage() {
    const [modalOpen, setModalOpen] = useState(false);
    const [detailRow, setDetailRow] = useState<LeaveRequest | null>(null);
    const queryClient = useQueryClient();

    const { params, setParams, setPage, reset } = useListParams<LeaveRequestListParams>(LEAVE_REQUEST_LIST_SPEC);
    const listParams = useMemo(() => compactParams(params), [params]);
    const narrowed = narrowingKeys(params).length > 0;

    // The box's text as typed; it becomes `q` on Enter / the search button,
    // never per keystroke. Re-seeded when the URL's q changes under it.
    const [qDraft, setQDraft] = useState(params.q ?? '');
    useEffect(() => setQDraft(params.q ?? ''), [params.q]);

    const query = useQuery({
        queryKey: ['hrms', 'leave-requests', 'list', listParams],
        queryFn: () => listLeaveRequests(listParams),
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

    const { control, handleSubmit, reset: resetForm, formState: { errors } } = useForm<RequestFormValues>({
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
            resetForm();
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

    // Three different empty tables: a term that matched nothing names the
    // term; a filter that holds nothing offers it back; only the bare page
    // may say there are no requests at all.
    const emptyText = params.q ? (
        <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description={noMatchLine('leave requests', params.q)}>
            <Button size="small" onClick={() => setParams({ q: undefined })}>
                Clear search
            </Button>
        </Empty>
    ) : narrowed ? (
        <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="No leave requests match these filters.">
            <Button size="small" onClick={reset}>
                Clear filters
            </Button>
        </Empty>
    ) : (
        'No leave requests yet.'
    );

    return (
        <>
            <Space style={{ marginBottom: 16, justifyContent: 'space-between', width: '100%' }} wrap>
                <Typography.Title level={3} style={{ margin: 0 }}>Leave Requests</Typography.Title>
                <Space wrap>
                    <Input.Search
                        allowClear
                        placeholder="Employee code, name, department"
                        style={{ width: 260 }}
                        value={qDraft}
                        onChange={(event) => setQDraft(event.target.value)}
                        onSearch={(value) => setParams({ q: value.trim() || undefined })}
                    />
                    <Select<LeaveRequestStatus | ''>
                        value={params.status ?? ''}
                        style={{ width: 150 }}
                        options={STATUS_FILTER}
                        onChange={(value) => setParams({ status: value || undefined })}
                    />
                    <Select<number>
                        allowClear
                        showSearch
                        optionFilterProp="label"
                        placeholder="Employee"
                        style={{ width: 220 }}
                        value={params.employee_id}
                        options={employeeOptions}
                        onChange={(value) => setParams({ employee_id: value ?? undefined })}
                    />
                    <Button type="primary" onClick={() => setModalOpen(true)}>New Leave Request</Button>
                </Space>
            </Space>

            <Space style={{ marginBottom: 8 }} wrap>
                <Typography.Text type="secondary">{pageRangeLine(query.data?.meta, 'leave requests')}</Typography.Text>
                {narrowed ? (
                    <Button size="small" onClick={reset}>
                        Clear
                    </Button>
                ) : null}
            </Space>

            {/* placeholderData keeps stale rows on a failed refetch, so
                emptyText never shows the failure — this line does. */}
            <ListReadAlert state={query} entity="leave requests" />

            <Table<LeaveRequest>
                sticky={TABLE_STICKY}
                scroll={{ x: 'max-content' }}
                rowKey="id"
                loading={query.isFetching}
                dataSource={query.data?.data}
                // SORTED BY THE SERVER: sortOrder-controlled, re-queried.
                onChange={(_pagination, _filters, sorter, extra) => {
                    if (extra.action !== 'sort') return;
                    setParams({ sort: sortParamFromSorter(sorter, LEAVE_REQUEST_SORT_FIELDS, LEAVE_REQUEST_DEFAULT_SORT) });
                }}
                pagination={serverPagination(query.data?.meta, setPage, 'leave requests')}
                locale={{ emptyText: <ListEmpty state={query} entity="leave requests" empty={emptyText} /> }}
                columns={[
                    // Names through relations, not columns of this table: no server sort.
                    { title: 'Employee', render: (_, row) => row.employee?.name },
                    { title: 'Leave Type', render: (_, row) => row.leave_type.name },
                    {
                        title: 'Start',
                        dataIndex: 'start_date',
                        key: 'start_date',
                        sorter: true,
                        sortOrder: columnSortOrder('start_date', params.sort, LEAVE_REQUEST_DEFAULT_SORT),
                    },
                    {
                        title: 'End',
                        dataIndex: 'end_date',
                        key: 'end_date',
                        sorter: true,
                        sortOrder: columnSortOrder('end_date', params.sort, LEAVE_REQUEST_DEFAULT_SORT),
                    },
                    {
                        title: 'Days',
                        dataIndex: 'days',
                        key: 'days',
                        sorter: true,
                        sortOrder: columnSortOrder('days', params.sort, LEAVE_REQUEST_DEFAULT_SORT),
                    },
                    {
                        title: 'Status',
                        dataIndex: 'status',
                        key: 'status',
                        sorter: true,
                        sortOrder: columnSortOrder('status', params.sort, LEAVE_REQUEST_DEFAULT_SORT),
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
