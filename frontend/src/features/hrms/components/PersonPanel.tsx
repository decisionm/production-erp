import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Button, Drawer, Empty, Input, Segmented, Select, Space, Table, Tag, TimePicker, Typography } from 'antd';
import dayjs from 'dayjs';
import { useEffect, useMemo, useState } from 'react';
import { listAttendanceImportLines, resolveAttendanceImportLine } from '@/features/hrms/api';
import { dayLabel, issueLabel, punchLine } from '@/features/hrms/attendanceReview';
import { RESOLUTION_LABELS, defaultResolution } from '@/features/hrms/list';
import type { AttendanceImportEmployee, AttendanceImportLine, AttendanceImportResolution } from '@/features/hrms/types';
import { showApiError } from '@/lib/showApiError';

interface Draft {
    resolution: AttendanceImportResolution;
    check_in?: string;
    check_out?: string;
    notes?: string;
}

const RESOLUTION_OPTIONS = (Object.keys(RESOLUTION_LABELS) as AttendanceImportResolution[]).map((value) => ({
    value,
    label: RESOLUTION_LABELS[value],
}));

const timed = (resolution: AttendanceImportResolution) => resolution === 'present' || resolution === 'half_day';

/**
 * ONE PERSON'S MONTH, ANSWERED IN ONE SITTING.
 *
 * The screen this replaces asked for one modal per day: open, choose, save,
 * close, find the next row, repeat. A person with eleven bad days cost
 * eleven of those. Here their days are a single list with the answer
 * already filled in beside each one — the reviewer changes what is wrong
 * and saves the person, not the day.
 *
 * Each day is still saved through the SAME per-line endpoint the old modal
 * used, one request each, so nothing about what reaches `attendances`
 * changes. Only the number of times a human has to click does.
 */
export default function PersonPanel({
    importId,
    person,
    open,
    onClose,
    mayWrite,
}: {
    importId: number;
    person: AttendanceImportEmployee | null;
    open: boolean;
    onClose: () => void;
    mayWrite: boolean;
}) {
    const queryClient = useQueryClient();
    const [scope, setScope] = useState<'open' | ''>('open');
    const [drafts, setDrafts] = useState<Record<number, Draft>>({});
    const [saved, setSaved] = useState<number | null>(null);

    const code = person?.employee_code;

    const lines = useQuery({
        queryKey: ['hrms', 'attendance-imports', importId, 'lines', 'person', code, scope],
        queryFn: () =>
            listAttendanceImportLines(importId, {
                employee_code: code,
                issue: scope || undefined,
                per_page: 100,
            }),
        enabled: open && Boolean(code),
    });

    // A fresh person starts from a clean sheet of drafts, and every day
    // opens on the answer the spec says is most likely right for it.
    useEffect(() => {
        if (!lines.data) return;
        const next: Record<number, Draft> = {};
        for (const line of lines.data.data) {
            next[line.id] = {
                resolution: defaultResolution(line),
                check_in: line.resolved_check_in ?? line.first_in ?? undefined,
                check_out: line.resolved_check_out ?? line.last_out ?? undefined,
                notes: line.notes ?? undefined,
            };
        }
        setDrafts(next);
    }, [lines.data]);

    useEffect(() => {
        if (open) setSaved(null);
    }, [open, code]);

    const rows = lines.data?.data ?? [];
    const openRows = useMemo(() => rows.filter((line) => line.issue && !line.resolution), [rows]);

    const set = (id: number, patch: Partial<Draft>) => setDrafts((current) => ({ ...current, [id]: { ...current[id], ...patch } }));

    const save = useMutation({
        mutationFn: async (targets: AttendanceImportLine[]) => {
            let done = 0;
            for (const line of targets) {
                const draft = drafts[line.id];
                if (!draft) continue;
                await resolveAttendanceImportLine(importId, line.id, {
                    resolution: draft.resolution,
                    check_in: timed(draft.resolution) ? draft.check_in || null : null,
                    check_out: timed(draft.resolution) ? draft.check_out || null : null,
                    notes: draft.notes || null,
                });
                done++;
            }

            return done;
        },
        onSuccess: (done) => {
            setSaved(done);
            queryClient.invalidateQueries({ queryKey: ['hrms', 'attendance-imports'] });
        },
        onError: (error) => showApiError(error, 'Could not save these days'),
    });

    return (
        <Drawer
            open={open}
            onClose={onClose}
            width={720}
            destroyOnHidden
            title={person ? `${person.employee_code} — ${person.employee_name}` : ''}
            extra={
                mayWrite && openRows.length > 0 ? (
                    <Button type="primary" loading={save.isPending} onClick={() => save.mutate(openRows)}>
                        {`Save ${openRows.length} ${openRows.length === 1 ? 'day' : 'days'}`}
                    </Button>
                ) : null
            }
        >
            {person ? (
                <Space direction="vertical" size="middle" style={{ width: '100%' }}>
                    <Space wrap>
                        <Typography.Text type="secondary">
                            {[person.department, person.designation].filter(Boolean).join(' · ') || 'No department recorded'}
                        </Typography.Text>
                        {person.known ? null : <Tag color="purple">Not in the employee master</Tag>}
                    </Space>

                    {person.known ? null : (
                        <Alert
                            type="warning"
                            showIcon
                            message={`${person.employee_code} has no employee record, so these days cannot be answered yet.`}
                            action={
                                <Button size="small" href="/hrms/employees" target="_blank">
                                    Employees
                                </Button>
                            }
                        />
                    )}

                    {saved === null ? null : (
                        <Alert type="success" showIcon message={`${saved} ${saved === 1 ? 'day' : 'days'} saved.`} />
                    )}

                    <Segmented
                        value={scope}
                        onChange={(value) => setScope(value as 'open' | '')}
                        options={[
                            { value: 'open', label: `Needs an answer (${person.open_count})` },
                            { value: '', label: `Whole month (${person.day_count})` },
                        ]}
                    />

                    <Table<AttendanceImportLine>
                        size="small"
                        rowKey="id"
                        loading={lines.isFetching}
                        dataSource={rows}
                        pagination={false}
                        scroll={{ x: 'max-content' }}
                        locale={{
                            emptyText: (
                                <Empty
                                    image={Empty.PRESENTED_IMAGE_SIMPLE}
                                    description={scope === 'open' ? 'Nothing left to answer for this person.' : 'No days.'}
                                />
                            ),
                        }}
                        columns={[
                            {
                                title: 'Day',
                                dataIndex: 'date',
                                render: (date: string) => dayLabel(date),
                            },
                            {
                                title: 'Punched',
                                render: (_, line) => (
                                    <Typography.Text type={line.issue ? undefined : 'secondary'}>{punchLine(line)}</Typography.Text>
                                ),
                            },
                            {
                                title: 'Issue',
                                render: (_, line) =>
                                    line.issue ? <Tag color="orange">{issueLabel(line.issue)}</Tag> : <Typography.Text type="secondary">—</Typography.Text>,
                            },
                            {
                                title: 'Counts as',
                                width: 170,
                                render: (_, line) =>
                                    mayWrite && person.known ? (
                                        <Select<AttendanceImportResolution>
                                            size="small"
                                            style={{ width: 150 }}
                                            value={drafts[line.id]?.resolution}
                                            options={RESOLUTION_OPTIONS}
                                            onChange={(value) => set(line.id, { resolution: value })}
                                        />
                                    ) : (
                                        RESOLUTION_LABELS[drafts[line.id]?.resolution ?? 'absent']
                                    ),
                            },
                            {
                                title: 'In / Out',
                                width: 230,
                                render: (_, line) => {
                                    const draft = drafts[line.id];
                                    if (!draft || !timed(draft.resolution)) return <Typography.Text type="secondary">—</Typography.Text>;

                                    return (
                                        <Space size={4}>
                                            <TimePicker
                                                size="small"
                                                format="HH:mm"
                                                style={{ width: 100 }}
                                                disabled={!mayWrite || !person.known}
                                                value={draft.check_in ? dayjs(draft.check_in, 'HH:mm') : null}
                                                onChange={(_, text) => set(line.id, { check_in: (text as string) || undefined })}
                                            />
                                            <TimePicker
                                                size="small"
                                                format="HH:mm"
                                                style={{ width: 100 }}
                                                disabled={!mayWrite || !person.known}
                                                value={draft.check_out ? dayjs(draft.check_out, 'HH:mm') : null}
                                                onChange={(_, text) => set(line.id, { check_out: (text as string) || undefined })}
                                            />
                                        </Space>
                                    );
                                },
                            },
                            {
                                title: 'Note',
                                width: 180,
                                render: (_, line) => (
                                    <Input
                                        size="small"
                                        disabled={!mayWrite || !person.known}
                                        value={drafts[line.id]?.notes ?? ''}
                                        onChange={(event) => set(line.id, { notes: event.target.value })}
                                    />
                                ),
                            },
                        ]}
                    />
                </Space>
            ) : null}
        </Drawer>
    );
}
