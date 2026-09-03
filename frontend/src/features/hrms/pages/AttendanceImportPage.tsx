import { DownloadOutlined } from '@ant-design/icons';
import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
    Alert,
    Button,
    Empty,
    Form,
    Input,
    Modal,
    Popconfirm,
    Progress,
    Radio,
    Segmented,
    Select,
    Space,
    Table,
    Tag,
    TimePicker,
    Typography,
} from 'antd';
import dayjs from 'dayjs';
import { useEffect, useMemo, useRef, useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { Link, useParams, useSearchParams } from 'react-router-dom';
import { z } from 'zod';
import { hasManageAccess } from '@/features/auth/permissions';
import { useAuthStore } from '@/features/auth/store';
import { exportErrorSentence, runExport } from '@/features/exports/api';
import {
    applyAttendanceImport,
    bulkResolveAttendanceImportLines,
    getAttendanceImport,
    listAttendanceImportEmployees,
    listAttendanceImportLines,
    resolveAttendanceImportLine,
} from '@/features/hrms/api';
import {
    bulkOffer,
    bulkOutcome,
    dayLabel,
    progressLine,
    progressPercent,
    punchLine,
} from '@/features/hrms/attendanceReview';
import MonthStrip, { MonthStripLegend } from '@/features/hrms/components/MonthStrip';
import PersonPanel from '@/features/hrms/components/PersonPanel';
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
    AttendanceImportEmployee,
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

const PEOPLE_PER_PAGE = 25;

/**
 * ONE PUNCH-REPORT RUN, REVIEWED AT THE GRAIN THE WORK IS AT.
 *
 * A July is 1,829 employee-days and 589 of them need an answer, so the
 * screen's only real question is how a person gets through 589 decisions
 * without giving up. It answers that three ways, in the order a reviewer
 * uses them:
 *
 *   PEOPLE (the landing view) — one row per person with their month beside
 *   them, so the work is visible as shape rather than as a row count, and
 *   one panel answers everything one person needs.
 *
 *   DAYS — the flat list, which is the right tool when the question is
 *   about a kind of problem rather than a person; and when a kind is
 *   selected, ONE BUTTON answers every day still carrying it.
 *
 *   THE PROGRESS LINE — how much is left, always, because a screen that
 *   only says "589 open" and disables Apply tells you that you are stuck
 *   without telling you how far you have come.
 */
export default function AttendanceImportPage() {
    const { id: idParam } = useParams();
    const id = Number(idParam);
    const queryClient = useQueryClient();
    const user = useAuthStore((state) => state.user);
    const mayWrite = hasManageAccess(user, 'hrms');

    // The view rides on the URL, so a link to this screen shows what the
    // sender was looking at. `writeListParams` keeps unmanaged keys, so the
    // day list's own filter never wipes it.
    const [searchParams, setSearchParams] = useSearchParams();
    const view: 'people' | 'days' = searchParams.get('view') === 'days' ? 'days' : 'people';
    const setView = (next: 'people' | 'days') =>
        setSearchParams(
            (current) => {
                const out = new URLSearchParams(current);
                if (next === 'days') out.set('view', 'days');
                else out.delete('view');

                return out;
            },
            { replace: true },
        );

    const { params, setParams, setPage, reset } = useListParams<AttendanceImportLineListParams>(ATTENDANCE_IMPORT_LINE_LIST_SPEC);
    const listParams = useMemo(() => compactParams(params), [params]);
    const narrowed = narrowingKeys(params).length > 0;
    const [qDraft, setQDraft] = useState(params.q ?? '');
    useEffect(() => setQDraft(params.q ?? ''), [params.q]);

    // The day list opens on the ISSUES, once, on a first visit that named
    // no filter. The bare list of every day is still one chip away; it is
    // just not what a reviewer is greeted by.
    const defaulted = useRef(false);
    useEffect(() => {
        if (defaulted.current) return;
        defaulted.current = true;
        if (params.issue === undefined && params.q === undefined) setParams({ issue: 'open' });
    }, [params.issue, params.q, setParams]);

    const run = useQuery({
        queryKey: ['hrms', 'attendance-imports', id],
        queryFn: () => getAttendanceImport(id),
        enabled: Number.isFinite(id),
    });
    const data = run.data;

    // The people list keeps its own search and page in component state
    // rather than the URL: the URL already carries the day list's filter,
    // and one address cannot hold two lists' narrowing without them
    // overwriting each other. Both are still narrowed and paged by the
    // SERVER, which is the part that matters.
    const [peopleQ, setPeopleQ] = useState('');
    const [peopleQDraft, setPeopleQDraft] = useState('');
    const [peoplePage, setPeoplePage] = useState(1);

    const people = useQuery({
        queryKey: ['hrms', 'attendance-imports', id, 'employees', { q: peopleQ, page: peoplePage }],
        queryFn: () =>
            listAttendanceImportEmployees(id, { q: peopleQ || undefined, page: peoplePage, per_page: PEOPLE_PER_PAGE }),
        enabled: Number.isFinite(id) && view === 'people',
        placeholderData: (previous) => previous,
    });

    const lines = useQuery({
        queryKey: ['hrms', 'attendance-imports', id, 'lines', listParams],
        queryFn: () => listAttendanceImportLines(id, listParams),
        enabled: Number.isFinite(id) && view === 'days',
        placeholderData: (previous) => previous,
    });

    const invalidate = () => queryClient.invalidateQueries({ queryKey: ['hrms', 'attendance-imports'] });

    const [person, setPerson] = useState<AttendanceImportEmployee | null>(null);

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

    // ---- one answer for one kind of problem -----------------------------
    const offer = bulkOffer(params.issue, data?.counts);
    const [bulkTime, setBulkTime] = useState<string | undefined>(undefined);
    const [bulkSaid, setBulkSaid] = useState<string | null>(null);
    useEffect(() => setBulkSaid(null), [params.issue]);

    const bulk = useMutation({
        mutationFn: () =>
            bulkResolveAttendanceImportLines(id, {
                issue: offer!.issue,
                resolution: offer!.resolution,
                check_in: offer!.time === 'check_in' ? bulkTime : undefined,
                check_out: offer!.time === 'check_out' ? bulkTime : undefined,
            }),
        onSuccess: (result) => {
            setBulkSaid(bulkOutcome(result));
            invalidate();
        },
        onError: (error) => showApiError(error, 'Could not answer these days'),
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

            <Space style={{ marginBottom: 12, justifyContent: 'space-between', width: '100%' }} wrap>
                <Space wrap align="center">
                    <Typography.Title level={3} style={{ margin: 0 }}>
                        {data ? `${dayLabel(data.period_from)} – ${dayLabel(data.period_to)}` : 'Attendance Import'}
                    </Typography.Title>
                    {data ? <Tag color={statusColor[data.status]}>{data.status}</Tag> : null}
                    {data ? <Typography.Text type="secondary">{`${data.employee_count} people`}</Typography.Text> : null}
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

            {data ? (
                <div style={{ maxWidth: 520, marginBottom: 16 }}>
                    <Progress
                        percent={progressPercent(data.counts)}
                        status={data.open_count > 0 ? 'active' : 'success'}
                        size="small"
                    />
                    <Typography.Text type="secondary">{progressLine(data.counts, data.day_count)}</Typography.Text>
                </div>
            ) : null}

            <Space style={{ marginBottom: 12 }} wrap>
                <Segmented
                    value={view}
                    onChange={(value) => setView(value as 'people' | 'days')}
                    options={[
                        { value: 'people', label: `People${data ? ` (${data.employee_count})` : ''}` },
                        { value: 'days', label: `Days${data ? ` (${data.day_count.toLocaleString()})` : ''}` },
                    ]}
                />
            </Space>

            {view === 'people' ? (
                <>
                    <Space style={{ marginBottom: 12 }} wrap>
                        <Input.Search
                            allowClear
                            placeholder="Employee code or name"
                            style={{ width: 240 }}
                            value={peopleQDraft}
                            onChange={(event) => setPeopleQDraft(event.target.value)}
                            onSearch={(value) => {
                                setPeopleQ(value.trim());
                                setPeoplePage(1);
                            }}
                        />
                        <MonthStripLegend />
                    </Space>

                    <Space style={{ marginBottom: 8 }} wrap>
                        <Typography.Text type="secondary">{pageRangeLine(people.data?.meta, 'people')}</Typography.Text>
                    </Space>

                    <ListReadAlert state={people} entity="people" />

                    <Table<AttendanceImportEmployee>
                        sticky={TABLE_STICKY}
                        scroll={{ x: 'max-content' }}
                        rowKey="employee_code"
                        loading={people.isFetching}
                        dataSource={people.data?.data}
                        pagination={serverPagination(people.data?.meta, setPeoplePage, 'people')}
                        locale={{
                            emptyText: (
                                <ListEmpty
                                    state={people}
                                    entity="people"
                                    empty={
                                        peopleQ ? (
                                            <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description={noMatchLine('people', peopleQ)}>
                                                <Button
                                                    size="small"
                                                    onClick={() => {
                                                        setPeopleQ('');
                                                        setPeopleQDraft('');
                                                    }}
                                                >
                                                    Clear search
                                                </Button>
                                            </Empty>
                                        ) : (
                                            'Nobody in this run.'
                                        )
                                    }
                                />
                            ),
                        }}
                        columns={[
                            {
                                title: 'Employee',
                                render: (_, row) => (
                                    <Space direction="vertical" size={0}>
                                        <span style={{ whiteSpace: 'nowrap' }}>{`${row.employee_code} — ${row.employee_name}`}</span>
                                        <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                                            {row.known ? row.department ?? '' : 'Not in the employee master'}
                                        </Typography.Text>
                                    </Space>
                                ),
                            },
                            {
                                title: 'Month',
                                render: (_, row) => <MonthStrip days={row.days} />,
                            },
                            {
                                title: 'To answer',
                                width: 120,
                                render: (_, row) =>
                                    row.open_count > 0 ? (
                                        <Tag color="orange">{`${row.open_count} ${row.open_count === 1 ? 'day' : 'days'}`}</Tag>
                                    ) : (
                                        <Typography.Text type="secondary">Done</Typography.Text>
                                    ),
                            },
                            {
                                title: '',
                                width: 90,
                                render: (_, row) => (
                                    <Button size="small" onClick={() => setPerson(row)}>
                                        {row.open_count > 0 ? 'Answer' : 'View'}
                                    </Button>
                                ),
                            },
                        ]}
                    />
                </>
            ) : (
                <>
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

                    {offer && mayWrite && data?.status === 'review' ? (
                        <Alert
                            type="info"
                            style={{ marginBottom: 12 }}
                            message={
                                <Space wrap align="center">
                                    <span>{`Every one of these days asks the same question.`}</span>
                                    {offer.time ? (
                                        <TimePicker
                                            format="HH:mm"
                                            placeholder={offer.timeLabel}
                                            value={bulkTime ? dayjs(bulkTime, 'HH:mm') : null}
                                            onChange={(_, text) => setBulkTime((text as string) || undefined)}
                                        />
                                    ) : null}
                                    <Popconfirm
                                        title={offer.label}
                                        description="Days somebody has already answered are left alone."
                                        okText="Answer them"
                                        onConfirm={() => bulk.mutate()}
                                        disabled={offer.time !== null && !bulkTime}
                                    >
                                        <Button
                                            type="primary"
                                            loading={bulk.isPending}
                                            disabled={offer.time !== null && !bulkTime}
                                        >
                                            {offer.label}
                                        </Button>
                                    </Popconfirm>
                                </Space>
                            }
                        />
                    ) : null}

                    {bulkSaid ? (
                        <Alert type="success" showIcon style={{ marginBottom: 12 }} message={bulkSaid} closable onClose={() => setBulkSaid(null)} />
                    ) : null}

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
                            { title: 'Day', dataIndex: 'date', render: (date: string) => dayLabel(date) },
                            {
                                title: 'Punched',
                                render: (_, row) => punchLine(row),
                            },
                            {
                                title: 'Issue',
                                dataIndex: 'issue',
                                render: (issue: AttendanceImportIssue | null) =>
                                    issue ? <Tag color={issueColor[issue]}>{ISSUE_LABELS[issue]}</Tag> : null,
                            },
                            {
                                title: 'Counts as',
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
                            {
                                title: '',
                                render: (_, row) =>
                                    mayWrite && data?.status === 'review' ? (
                                        <Button size="small" onClick={() => open(row)}>
                                            Correct
                                        </Button>
                                    ) : null,
                            },
                        ]}
                    />
                </>
            )}

            <PersonPanel
                importId={id}
                person={person}
                open={person !== null}
                onClose={() => setPerson(null)}
                mayWrite={mayWrite && data?.status === 'review'}
            />

            <Modal
                maskClosable={false}
                title={editing ? `${editing.employee_code} — ${dayLabel(editing.date)}` : 'Correct'}
                open={editing !== null}
                onCancel={() => setEditing(null)}
                onOk={handleSubmit((values) => resolve.mutate(values))}
                confirmLoading={resolve.isPending}
                destroyOnHidden
            >
                <Form layout="vertical">
                    <Form.Item label="Counts as" validateStatus={errors.resolution ? 'error' : ''} help={errors.resolution?.message}>
                        <Controller
                            name="resolution"
                            control={control}
                            render={({ field }) => <Select {...field} options={resolutionOptions} />}
                        />
                    </Form.Item>
                    {timed ? (
                        <Space>
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
                    <Form.Item label="Note">
                        <Controller name="notes" control={control} render={({ field }) => <Input.TextArea {...field} rows={2} />} />
                    </Form.Item>
                </Form>
            </Modal>
        </>
    );
}
