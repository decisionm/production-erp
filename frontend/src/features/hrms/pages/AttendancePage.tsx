import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, DatePicker, Empty, Form, Input, Modal, Select, Space, Table, Tag, Typography } from 'antd';
import dayjs from 'dayjs';
import { useEffect, useMemo, useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { z } from 'zod';
import { hasManageAccess } from '@/features/auth/permissions';
import { useAuthStore } from '@/features/auth/store';
import { listAttendance, listAllEmployees, markAttendance } from '@/features/hrms/api';
import { type DateRange, rangeFor } from '@/features/hrms/attendanceRange';
import AttendanceRangeBar from '@/features/hrms/components/AttendanceRangeBar';
import DepartmentAttendanceCard from '@/features/hrms/components/DepartmentAttendanceCard';
import PersonAttendanceCard from '@/features/hrms/components/PersonAttendanceCard';
import { ATTENDANCE_DEFAULT_SORT, ATTENDANCE_LIST_SPEC, ATTENDANCE_SORT_FIELDS, noMatchLine, pageRangeLine } from '@/features/hrms/list';
import type { Attendance, AttendanceListParams, AttendanceStatus } from '@/features/hrms/types';
import { ListEmpty, ListReadAlert } from '@/lib/ListEmpty';
import { compactParams, narrowingKeys } from '@/lib/listParams';
import { TABLE_STICKY, serverPagination } from '@/lib/tableProps';
import { columnSortOrder, sortParamFromSorter } from '@/lib/tableSort';
import { useListParams } from '@/lib/useListParams';

const attendanceSchema = z.object({
    employee_id: z.number({ error: 'Employee is required' }),
    date: z.string({ error: 'Date is required' }),
    status: z.enum(['present', 'absent', 'half_day', 'on_leave'], { error: 'Status is required' }),
    notes: z.string().optional(),
});
type AttendanceFormValues = z.infer<typeof attendanceSchema>;

const statusColor: Record<AttendanceStatus | 'week_off', string> = {
    present: 'green',
    absent: 'red',
    half_day: 'orange',
    on_leave: 'blue',
    week_off: 'default',
};

const statusOptions: { value: AttendanceStatus; label: string }[] = [
    { value: 'present', label: 'Present' },
    { value: 'absent', label: 'Absent' },
    { value: 'half_day', label: 'Half Day' },
    { value: 'on_leave', label: 'On Leave' },
];

const STATUS_FILTER: { value: AttendanceStatus | ''; label: string }[] = [{ value: '', label: 'All statuses' }, ...statusOptions];

/**
 * THE ATTENDANCE LIST. Search, status, employee, date range, page and page
 * size live in the URL (useListParams) and the SERVER does the narrowing
 * (ListAttendanceRequest): `q` goes THROUGH the employee — code, name,
 * department, designation; the range is on the attendance DATE. The pager
 * is wired to the server's meta, so a day older than the newest screen is
 * reachable — a month's marks were not, from this page, until it was.
 */
export default function AttendancePage() {
    const [modalOpen, setModalOpen] = useState(false);
    const queryClient = useQueryClient();
    const mayManage = hasManageAccess(useAuthStore((state) => state.user), 'hrms');

    // The page opens on the month in progress: the punch report arrives a
    // month at a time, so that is the period somebody is usually asking about.
    const [range, setRange] = useState<DateRange>(() => rangeFor('this_month'));

    const { params, setParams, setPage, reset } = useListParams<AttendanceListParams>(ATTENDANCE_LIST_SPEC);
    const listParams = useMemo(() => compactParams(params), [params]);
    const narrowed = narrowingKeys(params).length > 0;

    // The box's text as typed; it becomes `q` on Enter / the search button,
    // never per keystroke. Re-seeded when the URL's q changes under it.
    const [qDraft, setQDraft] = useState(params.q ?? '');
    useEffect(() => setQDraft(params.q ?? ''), [params.q]);

    const query = useQuery({
        queryKey: ['hrms', 'attendance', 'list', listParams],
        queryFn: () => listAttendance(listParams),
        placeholderData: (previous) => previous,
    });
    const { data: employees } = useQuery({ queryKey: ['hrms', 'employees', 'all'], queryFn: listAllEmployees });
    const employeeOptions = employees?.data.map((e) => ({ value: e.id, label: `${e.employee_code} — ${e.name}` })) ?? [];

    const { control, handleSubmit, reset: resetForm, formState: { errors } } = useForm<AttendanceFormValues>({
        resolver: zodResolver(attendanceSchema),
        defaultValues: { status: 'present', notes: '' },
    });

    const mutation = useMutation({
        mutationFn: markAttendance,
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['hrms', 'attendance'] });
            setModalOpen(false);
            resetForm({ status: 'present', notes: '' });
        },
        onError: (error: any) => {
            Modal.error({ title: 'Could not mark attendance', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    // Three different empty tables: a term that matched nothing names the
    // term; a filter that holds nothing offers it back; only the bare page
    // may say nothing has been marked at all.
    const emptyText = params.q ? (
        <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description={noMatchLine('attendance records', params.q)}>
            <Button size="small" onClick={() => setParams({ q: undefined })}>
                Clear search
            </Button>
        </Empty>
    ) : narrowed ? (
        <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="No attendance records match these filters.">
            <Button size="small" onClick={reset}>
                Clear filters
            </Button>
        </Empty>
    ) : (
        'No attendance recorded yet.'
    );

    return (
        <>
            <Typography.Title level={3} style={{ margin: '0 0 12px' }}>
                Attendance
            </Typography.Title>

            {/* One period drives both halves — see AttendanceRangeBar. */}
            <div style={{ marginBottom: 16 }}>
                <AttendanceRangeBar range={range} onChange={setRange} />
            </div>

            <Space orientation="vertical" size="middle" style={{ width: '100%', marginBottom: 24 }}>
                <PersonAttendanceCard range={range} />
                {/* The whole factory's numbers are the manager's read, and the
                    endpoint refuses a view-only login as well as hiding it. */}
                {mayManage ? <DepartmentAttendanceCard range={range} /> : null}
            </Space>

            <Space style={{ marginBottom: 16, justifyContent: 'space-between', width: '100%' }} wrap>
                <Typography.Title level={4} style={{ margin: 0 }}>
                    All marks
                </Typography.Title>
                <Space wrap>
                    <Input.Search
                        allowClear
                        placeholder="Employee code, name, department"
                        style={{ width: 260 }}
                        value={qDraft}
                        onChange={(event) => setQDraft(event.target.value)}
                        onSearch={(value) => setParams({ q: value.trim() || undefined })}
                    />
                    <Select<AttendanceStatus | ''>
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
                    <DatePicker.RangePicker
                        allowEmpty={[true, true]}
                        value={[params.from ? dayjs(params.from) : null, params.to ? dayjs(params.to) : null]}
                        onChange={(_, dateStrings) =>
                            setParams({ from: dateStrings[0] || undefined, to: dateStrings[1] || undefined })
                        }
                    />
                    <Button type="primary" onClick={() => setModalOpen(true)}>Mark Attendance</Button>
                </Space>
            </Space>

            <Space style={{ marginBottom: 8 }} wrap>
                <Typography.Text type="secondary">{pageRangeLine(query.data?.meta, 'attendance records')}</Typography.Text>
                {narrowed ? (
                    <Button size="small" onClick={reset}>
                        Clear
                    </Button>
                ) : null}
            </Space>

            {/* placeholderData keeps stale rows on a failed refetch, so
                emptyText never shows the failure — this line does. */}
            <ListReadAlert state={query} entity="attendance records" />

            <Table<Attendance>
                sticky={TABLE_STICKY}
                scroll={{ x: 'max-content' }}
                // An uploaded day has no id — see Attendance['key'].
                rowKey="key"
                loading={query.isFetching}
                dataSource={query.data?.data}
                // SORTED BY THE SERVER: sortOrder-controlled, re-queried.
                onChange={(_pagination, _filters, sorter, extra) => {
                    if (extra.action !== 'sort') return;
                    setParams({ sort: sortParamFromSorter(sorter, ATTENDANCE_SORT_FIELDS, ATTENDANCE_DEFAULT_SORT) });
                }}
                pagination={serverPagination(query.data?.meta, setPage, 'attendance records')}
                locale={{ emptyText: <ListEmpty state={query} entity="attendance records" empty={emptyText} /> }}
                columns={[
                    // A name through the relation, not a column of this table: no server sort.
                    {
                        title: 'Employee',
                        render: (_, row) => (
                            <Space size={4}>
                                {row.employee?.name}
                                {row.provisional ? <Tag color="blue">upload</Tag> : null}
                            </Space>
                        ),
                    },
                    {
                        title: 'Date',
                        dataIndex: 'date',
                        key: 'date',
                        sorter: true,
                        sortOrder: columnSortOrder('date', params.sort, ATTENDANCE_DEFAULT_SORT),
                    },
                    {
                        title: 'Status',
                        dataIndex: 'status',
                        key: 'status',
                        sorter: true,
                        sortOrder: columnSortOrder('status', params.sort, ATTENDANCE_DEFAULT_SORT),
                        render: (status: Attendance['status']) =>
                            status === null ? (
                                <Tag color="gold">needs review</Tag>
                            ) : (
                                <Tag color={statusColor[status]}>{status}</Tag>
                            ),
                    },
                    { title: 'Notes', dataIndex: 'notes' },
                ]}
            />

            <Modal
                maskClosable={false}
                title="Mark Attendance"
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
                    <Form.Item label="Date" validateStatus={errors.date ? 'error' : ''} help={errors.date?.message}>
                        <Controller
                            name="date"
                            control={control}
                            render={({ field }) => (
                                <DatePicker
                                    style={{ width: '100%' }}
                                    onChange={(_, dateString) => field.onChange(dateString || undefined)}
                                />
                            )}
                        />
                    </Form.Item>
                    <Form.Item label="Status" validateStatus={errors.status ? 'error' : ''} help={errors.status?.message}>
                        <Controller
                            name="status"
                            control={control}
                            render={({ field }) => <Select {...field} options={statusOptions} />}
                        />
                    </Form.Item>
                    <Form.Item label="Notes">
                        <Controller name="notes" control={control} render={({ field }) => <Input.TextArea {...field} rows={2} />} />
                    </Form.Item>
                </Form>
            </Modal>
        </>
    );
}
