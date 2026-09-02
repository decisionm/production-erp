import { DownloadOutlined } from '@ant-design/icons';
import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, Empty, Form, Input, Modal, Radio, Select, Space, Table, Tag, TimePicker, Typography } from 'antd';
import dayjs from 'dayjs';
import { useEffect, useMemo, useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { Link, useParams } from 'react-router-dom';
import { z } from 'zod';
import { hasManageAccess } from '@/features/auth/permissions';
import { useAuthStore } from '@/features/auth/store';
import { exportErrorSentence, runExport } from '@/features/exports/api';
import {
    applyAttendanceImport,
    getAttendanceImport,
    listAttendanceImportLines,
    resolveAttendanceImportLine,
} from '@/features/hrms/api';
import {
    ATTENDANCE_IMPORT_LINE_LIST_SPEC,
    ISSUE_LABELS,
    RESOLUTION_LABELS,
    applyLabel,
    defaultResolution,
    lineFilterChips,
    noMatchLine,
    pageRangeLine,
} from '@/features/hrms/list';
import type {
    AttendanceImport,
    AttendanceImportIssue,
    AttendanceImportLine,
    AttendanceImportLineFilter,
    AttendanceImportLineListParams,
    AttendanceImportResolution,
} from '@/features/hrms/types';
import { ListEmpty, ListReadAlert } from '@/lib/ListEmpty';
import { downloadBlob } from '@/lib/csv';
import { compactParams, narrowingKeys } from '@/lib/listParams';
import { showApiError } from '@/lib/showApiError';
import { TABLE_STICKY, serverPagination } from '@/lib/tableProps';
import { useListParams } from '@/lib/useListParams';

const resolutionSchema = z.object({
    resolution: z.enum(['present', 'half_day', 'absent', 'on_leave', 'week_off'], { error: 'Resolution is required' }),
    check_in: z.string().optional(),
    check_out: z.string().optional(),
    notes: z.string().optional(),
});
type ResolutionFormValues = z.infer<typeof resolutionSchema>;

const issueColor: Record<AttendanceImportIssue, string> = {
    in_no_out: 'volcano',
    out_no_in: 'volcano',
    no_punch: 'red',
    unknown_employee: 'purple',
};

const resolutionColor: Record<AttendanceImportResolution, string> = {
    present: 'green',
    half_day: 'orange',
    absent: 'red',
    on_leave: 'blue',
    week_off: 'default',
};

const resolutionOptions = (Object.keys(RESOLUTION_LABELS) as AttendanceImportResolution[]).map((value) => ({
    value,
    label: RESOLUTION_LABELS[value],
}));

const statusColor: Record<AttendanceImport['status'], string> = { review: 'orange', applied: 'green' };

/**
 * One punch-report run: the review table (open issues first, the chips
 * and the search narrowed by the SERVER, paged), the correction modal,
 * Apply — disabled with the open count until nothing is open — and the
 * month sheet download once applied.
 */
export default function AttendanceImportPage() {
    const { id: idParam } = useParams();
    const id = Number(idParam);
    const queryClient = useQueryClient();
    const user = useAuthStore((state) => state.user);
    const mayWrite = hasManageAccess(user, 'hrms');

    const { params, setParams, setPage, reset } = useListParams<AttendanceImportLineListParams>(ATTENDANCE_IMPORT_LINE_LIST_SPEC);
    const listParams = useMemo(() => compactParams(params), [params]);
    const narrowed = narrowingKeys(params).length > 0;
    const [qDraft, setQDraft] = useState(params.q ?? '');
    useEffect(() => setQDraft(params.q ?? ''), [params.q]);

    const run = useQuery({
        queryKey: ['hrms', 'attendance-imports', id],
        queryFn: () => getAttendanceImport(id),
        enabled: Number.isFinite(id),
    });
    const lines = useQuery({
        queryKey: ['hrms', 'attendance-imports', id, 'lines', listParams],
        queryFn: () => listAttendanceImportLines(id, listParams),
        enabled: Number.isFinite(id),
        placeholderData: (previous) => previous,
    });

    const invalidate = () => queryClient.invalidateQueries({ queryKey: ['hrms', 'attendance-imports'] });

    const [editing, setEditing] = useState<AttendanceImportLine | null>(null);
    const { control, handleSubmit, reset: resetForm, watch, formState: { errors } } = useForm<ResolutionFormValues>({
        resolver: zodResolver(resolutionSchema),
        defaultValues: { resolution: 'present', notes: '' },
    });
    const timed = ['present', 'half_day'].includes(watch('resolution'));

    const open = (line: AttendanceImportLine) => {
        resetForm({
            resolution: defaultResolution(line),
            check_in: line.resolved_check_in ?? line.first_in ?? undefined,
            check_out: line.resolved_check_out ?? line.last_out ?? undefined,
            notes: line.notes ?? '',
        });
        setEditing(line);
    };

    const resolve = useMutation({
        mutationFn: (values: ResolutionFormValues) =>
            resolveAttendanceImportLine(id, editing!.id, {
                resolution: values.resolution,
                check_in: values.check_in || null,
                check_out: values.check_out || null,
                notes: values.notes || null,
            }),
        onSuccess: () => {
            setEditing(null);
            invalidate();
        },
        onError: (error) => showApiError(error, 'Could not save the correction'),
    });

    const apply = useMutation({
        mutationFn: () => applyAttendanceImport(id),
        onSuccess: invalidate,
        onError: (error) => showApiError(error, 'Could not apply'),
    });

    const [downloading, setDownloading] = useState(false);
    const download = async () => {
        setDownloading(true);
        try {
            const file = await runExport('attendance_month_sheet', { attendance_import_id: id });
            downloadBlob(file.filename, file.blob);
        } catch (error) {
            Modal.error({ title: 'Could not download', content: await exportErrorSentence(error) });
        } finally {
            setDownloading(false);
        }
    };

    const data = run.data;
    const chips = lineFilterChips(data?.counts);

    const emptyText = params.q ? (
        <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description={noMatchLine('lines', params.q)}>
            <Button size="small" onClick={() => setParams({ q: undefined })}>
                Clear search
            </Button>
        </Empty>
    ) : narrowed ? (
        <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="No lines match this filter.">
            <Button size="small" onClick={reset}>
                Clear filter
            </Button>
        </Empty>
    ) : (
        'No lines.'
    );

    return (
        <>
            <Space style={{ marginBottom: 4 }} wrap>
                <Link to="/hrms/attendance-imports">Attendance Import</Link>
            </Space>
            <Space style={{ marginBottom: 16, justifyContent: 'space-between', width: '100%' }} wrap>
                <Space wrap align="center">
                    <Typography.Title level={3} style={{ margin: 0 }}>
                        {data ? `${data.period_from} – ${data.period_to}` : 'Attendance Import'}
                    </Typography.Title>
                    {data ? <Tag color={statusColor[data.status]}>{data.status}</Tag> : null}
                    {data ? (
                        <Typography.Text type="secondary">
                            {`Employees ${data.employee_count} · Days ${data.day_count} · Issues ${data.issue_count} · Open ${data.open_count}`}
                        </Typography.Text>
                    ) : null}
                </Space>
                <Space wrap>
                    {mayWrite && data ? (
                        <Button
                            type="primary"
                            disabled={data.open_count > 0}
                            loading={apply.isPending}
                            onClick={() => apply.mutate()}
                        >
                            {applyLabel(data.open_count)}
                        </Button>
                    ) : null}
                    {data?.status === 'applied' ? (
                        <Button icon={<DownloadOutlined />} loading={downloading} onClick={download}>
                            Download month sheet
                        </Button>
                    ) : null}
                </Space>
            </Space>

            <Space style={{ marginBottom: 12 }} wrap>
                <Input.Search
                    allowClear
                    placeholder="Employee code or name"
                    style={{ width: 240 }}
                    value={qDraft}
                    onChange={(event) => setQDraft(event.target.value)}
                    onSearch={(value) => setParams({ q: value.trim() || undefined })}
                />
                <Radio.Group
                    optionType="button"
                    value={params.issue ?? ''}
                    onChange={(event) => setParams({ issue: (event.target.value as AttendanceImportLineFilter | '') || undefined })}
                    options={chips}
                />
            </Space>

            <Space style={{ marginBottom: 8 }} wrap>
                <Typography.Text type="secondary">{pageRangeLine(lines.data?.meta, 'lines')}</Typography.Text>
                {narrowed ? (
                    <Button size="small" onClick={reset}>
                        Clear
                    </Button>
                ) : null}
            </Space>

            <ListReadAlert state={lines} entity="lines" />

            <Table<AttendanceImportLine>
                sticky={TABLE_STICKY}
                scroll={{ x: 'max-content' }}
                rowKey="id"
                loading={lines.isFetching}
                dataSource={lines.data?.data}
                pagination={serverPagination(lines.data?.meta, setPage, 'lines')}
                locale={{ emptyText: <ListEmpty state={lines} entity="lines" empty={emptyText} /> }}
                columns={[
                    {
                        title: 'Employee',
                        render: (_, row) => (
                            <Space direction="vertical" size={0}>
                                <span style={{ whiteSpace: 'nowrap' }}>{`${row.employee_code} — ${row.employee?.name ?? row.employee_name}`}</span>
                                {row.issue === 'unknown_employee' && row.employee_id === null ? (
                                    <Link to={`/hrms/employees?q=${encodeURIComponent(row.employee_code)}`}>Employees</Link>
                                ) : null}
                            </Space>
                        ),
                    },
                    { title: 'Date', dataIndex: 'date' },
                    { title: 'Status', dataIndex: 'raw_status' },
                    {
                        title: 'Punched',
                        render: (_, row) => `${row.first_in ?? '—'} / ${row.last_out ?? '—'}`,
                    },
                    {
                        title: 'Issue',
                        dataIndex: 'issue',
                        render: (issue: AttendanceImportIssue | null) =>
                            issue ? <Tag color={issueColor[issue]}>{ISSUE_LABELS[issue]}</Tag> : null,
                    },
                    {
                        title: 'Resolution',
                        render: (_, row) =>
                            row.resolution ? (
                                <Space size={4}>
                                    <Tag color={resolutionColor[row.resolution]}>{RESOLUTION_LABELS[row.resolution]}</Tag>
                                    {row.resolved_check_in || row.resolved_check_out ? (
                                        <Typography.Text type="secondary">
                                            {`${row.resolved_check_in ?? '—'} / ${row.resolved_check_out ?? '—'}`}
                                        </Typography.Text>
                                    ) : null}
                                </Space>
                            ) : null,
                    },
                    { title: 'Notes', dataIndex: 'notes' },
                    {
                        title: '',
                        render: (_, row) =>
                            mayWrite ? (
                                <Button size="small" onClick={() => open(row)}>
                                    Correct
                                </Button>
                            ) : null,
                    },
                ]}
            />

            <Modal
                maskClosable={false}
                title={editing ? `${editing.employee_code} — ${editing.employee?.name ?? editing.employee_name} · ${editing.date}` : ''}
                open={editing !== null}
                onCancel={() => setEditing(null)}
                onOk={handleSubmit((values) => resolve.mutate(values))}
                okText="Save"
                confirmLoading={resolve.isPending}
                destroyOnHidden
            >
                <Form layout="vertical">
                    <Form.Item label="Punched">{`${editing?.first_in ?? '—'} / ${editing?.last_out ?? '—'}`}</Form.Item>
                    <Form.Item label="Resolution" validateStatus={errors.resolution ? 'error' : ''} help={errors.resolution?.message}>
                        <Controller
                            name="resolution"
                            control={control}
                            render={({ field }) => <Select {...field} options={resolutionOptions} />}
                        />
                    </Form.Item>
                    {timed ? (
                        <Space size="large">
                            <Form.Item label="Check in">
                                <Controller
                                    name="check_in"
                                    control={control}
                                    render={({ field }) => (
                                        <TimePicker
                                            format="HH:mm"
                                            value={field.value ? dayjs(field.value, 'HH:mm') : null}
                                            onChange={(_, timeString) => field.onChange(timeString || undefined)}
                                        />
                                    )}
                                />
                            </Form.Item>
                            <Form.Item label="Check out">
                                <Controller
                                    name="check_out"
                                    control={control}
                                    render={({ field }) => (
                                        <TimePicker
                                            format="HH:mm"
                                            value={field.value ? dayjs(field.value, 'HH:mm') : null}
                                            onChange={(_, timeString) => field.onChange(timeString || undefined)}
                                        />
                                    )}
                                />
                            </Form.Item>
                        </Space>
                    ) : null}
                    <Form.Item label="Notes">
                        <Controller name="notes" control={control} render={({ field }) => <Input.TextArea {...field} rows={2} />} />
                    </Form.Item>
                </Form>
            </Modal>
        </>
    );
}
