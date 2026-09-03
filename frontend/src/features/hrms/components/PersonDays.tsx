import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Button, Empty, Input, Segmented, Select, Space, Table, Tag, TimePicker, Typography } from 'antd';
import dayjs from 'dayjs';
import { useMemo, useState } from 'react';
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
 * ONE PERSON'S MONTH, ANSWERED WHERE THEY SIT.
 *
 * This opens INSIDE the person's row in the people table — no drawer, no
 * modal, no leaving the list. The screen it replaces asked for one modal
 * per day: open, choose, save, close, find the next row, repeat; a person
 * with eleven bad days cost eleven of those. Here their days are one list
 * with the answer already filled in beside each, the reviewer changes what
 * is wrong, and one button saves the person.
 *
 * Each day is still saved through the SAME per-line endpoint the modal
 * used, one request each, so nothing about what reaches `attendances`
 * changes. Only the number of times a human has to click does.
 */
export default function PersonDays({
    importId,
    person,
    mayWrite,
    onSaved,
}: {
    importId: number;
    person: AttendanceImportEmployee;
    mayWrite: boolean;
    /**
     * Told how many days were saved. The page owns the confirmation and
     * the collapse: saving refetches the people list, which remounts this
     * component, so a message held HERE would vanish the moment it was
     * earned.
     */
    onSaved?: (count: number) => void;
}) {
    const queryClient = useQueryClient();
    const [scope, setScope] = useState<'open' | ''>('open');
    const [drafts, setDrafts] = useState<Record<number, Draft>>({});

    const code = person.employee_code;

    const lines = useQuery({
        queryKey: ['hrms', 'attendance-imports', importId, 'lines', 'person', code, scope],
        queryFn: () =>
            listAttendanceImportLines(importId, {
                employee_code: code,
                issue: scope || undefined,
                per_page: 100,
            }),
    });

    const rows = lines.data?.data ?? [];

    /**
     * What a day is showing: the reviewer's own edit if they made one,
     * else the answer the spec says is most likely right for it — a day
     * nobody punched opens on Absent, a half-recorded one on Present, with
     * the clock's own times filled in.
     *
     * Derived at render rather than seeded into state by an effect, so the
     * answer is there on the first paint (and in a server render) instead
     * of appearing a tick later, and a refetch can never leave a stale
     * draft behind.
     */
    const draftFor = (line: AttendanceImportLine): Draft =>
        drafts[line.id] ?? {
            resolution: defaultResolution(line),
            check_in: line.resolved_check_in ?? line.first_in ?? undefined,
            check_out: line.resolved_check_out ?? line.last_out ?? undefined,
            notes: line.notes ?? undefined,
        };
    const openRows = useMemo(() => rows.filter((line) => line.issue && !line.resolution), [rows]);
    const editable = mayWrite && person.known;

    const set = (line: AttendanceImportLine, patch: Partial<Draft>) =>
        setDrafts((current) => ({ ...current, [line.id]: { ...draftFor(line), ...patch } }));

    const save = useMutation({
        mutationFn: async (targets: AttendanceImportLine[]) => {
            let done = 0;
            for (const line of targets) {
                const draft = draftFor(line);
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
            onSaved?.(done);
            queryClient.invalidateQueries({ queryKey: ['hrms', 'attendance-imports'] });
        },
        onError: (error) => showApiError(error, 'Could not save these days'),
    });

    return (
        <Space orientation="vertical" size="middle" style={{ width: '100%' }}>
            <Space style={{ width: '100%', justifyContent: 'space-between' }} wrap>
                <Space wrap>
                    <Segmented
                        value={scope}
                        onChange={(value) => setScope(value as 'open' | '')}
                        options={[
                            { value: 'open', label: `Needs an answer (${person.open_count})` },
                            { value: '', label: `Whole month (${person.day_count})` },
                        ]}
                    />
                    <Typography.Text type="secondary">
                        {[person.department, person.designation].filter(Boolean).join(' · ') || 'No department recorded'}
                    </Typography.Text>
                </Space>
                {editable && openRows.length > 0 ? (
                    <Button type="primary" loading={save.isPending} onClick={() => save.mutate(openRows)}>
                        {`Save ${openRows.length} ${openRows.length === 1 ? 'day' : 'days'}`}
                    </Button>
                ) : null}
            </Space>

            {person.known ? null : (
                <Alert
                    type="warning"
                    showIcon
                    message={`${person.employee_code} has no employee record, so these days cannot be answered yet.`}
                    action={
                        <Button size="small" href={`/hrms/employees?q=${encodeURIComponent(person.employee_code)}`}>
                            Employees
                        </Button>
                    }
                />
            )}

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
                    { title: 'Day', dataIndex: 'date', render: (date: string) => dayLabel(date) },
                    {
                        title: 'Punched',
                        render: (_, line) => (
                            <Typography.Text type={line.issue ? undefined : 'secondary'}>{punchLine(line)}</Typography.Text>
                        ),
                    },
                    {
                        title: 'Issue',
                        render: (_, line) =>
                            line.issue ? (
                                <Tag color="orange">{issueLabel(line.issue)}</Tag>
                            ) : (
                                <Typography.Text type="secondary">—</Typography.Text>
                            ),
                    },
                    {
                        title: 'Counts as',
                        width: 170,
                        render: (_, line) =>
                            editable ? (
                                <Select<AttendanceImportResolution>
                                    size="small"
                                    style={{ width: 150 }}
                                    value={draftFor(line).resolution}
                                    options={RESOLUTION_OPTIONS}
                                    onChange={(value) => set(line, { resolution: value })}
                                />
                            ) : (
                                RESOLUTION_LABELS[draftFor(line).resolution]
                            ),
                    },
                    {
                        title: 'In / Out',
                        width: 230,
                        render: (_, line) => {
                            const draft = draftFor(line);
                            if (!timed(draft.resolution)) return <Typography.Text type="secondary">—</Typography.Text>;

                            return (
                                <Space size={4}>
                                    <TimePicker
                                        size="small"
                                        format="HH:mm"
                                        style={{ width: 100 }}
                                        disabled={!editable}
                                        value={draft.check_in ? dayjs(draft.check_in, 'HH:mm') : null}
                                        onChange={(_, text) => set(line, { check_in: (text as string) || undefined })}
                                    />
                                    <TimePicker
                                        size="small"
                                        format="HH:mm"
                                        style={{ width: 100 }}
                                        disabled={!editable}
                                        value={draft.check_out ? dayjs(draft.check_out, 'HH:mm') : null}
                                        onChange={(_, text) => set(line, { check_out: (text as string) || undefined })}
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
                                disabled={!editable}
                                value={draftFor(line).notes ?? ''}
                                onChange={(event) => set(line, { notes: event.target.value })}
                            />
                        ),
                    },
                ]}
            />
        </Space>
    );
}
