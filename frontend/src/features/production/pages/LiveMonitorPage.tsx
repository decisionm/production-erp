import { useQuery } from '@tanstack/react-query';
import { Alert, Card, Col, Row, Space, Table, Tag, Typography } from 'antd';
import dayjs from 'dayjs';
import { Link } from 'react-router-dom';
import {
    listActiveBatches,
    listMachineDowntimeLogs,
    listMoldChangeLogs,
    listShiftProductionEntries,
    listWorkCenters,
} from '@/features/production/api';
import { productionDateFor } from '@/features/production/shiftClock';
import type { MachineDowntimeLog, MoldChangeLog, ShiftProductionEntry, WorkCenter } from '@/features/production/types';
import { listTallySyncEntries } from '@/features/tally-sync/api';
import { itemLabel } from '@/lib/itemLabel';

/**
 * What the floor looks like right now, on one screen.
 *
 * Deliberately NOT the full management dashboard: no date range, no
 * drill-downs, no analytics. This answers only the questions someone
 * supervising a live shift has to answer in the next few minutes — which
 * machines are running, what is waiting for a signature, and what has failed
 * and needs a person. Anything that can wait until tomorrow is not here.
 *
 * Every figure is READ from an endpoint that already existed and is already
 * used elsewhere; this page adds no new API surface and writes nothing. That
 * is what makes it safe to add on the eve of go-live.
 *
 * The counts are derived the same way the Shift Floor derives them, from
 * `active-batches` rather than the paginated today-scoped entry list, so a
 * batch left running from an earlier shift cannot make a machine look idle
 * here while Start Batch refuses it. A monitor that disagrees with the screen
 * the supervisor acts on is worse than no monitor.
 */

/** Poll interval, matching the Shift Floor so the two screens agree. */
const REFRESH_MS = 20000;

const fmtTime = (iso: string | null | undefined): string => (iso ? dayjs(iso).format('DD MMM HH:mm') : '—');

const fmtQty = (v: string | null | undefined): string => {
    if (v === null || v === undefined || v === '') return '—';
    const n = parseFloat(v);
    return Number.isNaN(n) ? '—' : String(parseFloat(n.toFixed(2)));
};

/** A big number with a label — the row a supervisor reads first. */
function Stat({ label, value, tone, to }: { label: string; value: number | string; tone?: 'ok' | 'warn' | 'stop'; to?: string }) {
    const colour = tone === 'stop' ? '#cf1322' : tone === 'warn' ? '#ad6800' : tone === 'ok' ? '#237804' : undefined;
    const body = (
        <Card size="small" hoverable={to !== undefined} styles={{ body: { padding: '12px 14px' } }}>
            <Typography.Text type="secondary" style={{ fontSize: 12, display: 'block' }}>
                {label}
            </Typography.Text>
            <Typography.Text strong style={{ fontSize: 26, lineHeight: 1.2, color: colour }}>
                {value}
            </Typography.Text>
        </Card>
    );

    return to ? <Link to={to}>{body}</Link> : body;
}

export default function LiveMonitorPage() {
    const { data: workCenters } = useQuery({
        queryKey: ['production', 'work-centers', 'active'],
        queryFn: () => listWorkCenters(true),
    });
    const { data: activeBatches } = useQuery({
        queryKey: ['production', 'active-batches'],
        queryFn: listActiveBatches,
        refetchInterval: REFRESH_MS,
    });
    const { data: downtimeLogs } = useQuery({
        queryKey: ['production', 'machine-downtime-logs'],
        queryFn: listMachineDowntimeLogs,
        refetchInterval: REFRESH_MS,
    });
    const { data: moldChangeLogs } = useQuery({
        queryKey: ['production', 'mold-change-logs'],
        queryFn: listMoldChangeLogs,
        refetchInterval: REFRESH_MS,
    });
    // The two approval desks, asked for separately so each shows its own queue
    // rather than a combined "pending" nobody owns.
    const { data: awaitingPm } = useQuery({
        queryKey: ['production', 'shift-production-entries', 'pending'],
        queryFn: () => listShiftProductionEntries('pending'),
        refetchInterval: REFRESH_MS,
    });
    const { data: awaitingAccounts } = useQuery({
        queryKey: ['production', 'shift-production-entries', 'pm_approved'],
        queryFn: () => listShiftProductionEntries('pm_approved'),
        refetchInterval: REFRESH_MS,
    });
    const { data: tallyEntries } = useQuery({
        queryKey: ['tally-sync', 'entries'],
        queryFn: listTallySyncEntries,
        refetchInterval: REFRESH_MS,
    });

    const machines: WorkCenter[] = (workCenters?.data ?? []).filter((w) => w.is_active);

    // Same derivation as the Shift Floor: newest in-progress batch per machine,
    // across all shifts and dates.
    const runningByMachine = new Map<number, ShiftProductionEntry>();
    for (const entry of activeBatches?.data ?? []) {
        if (entry.batch_status !== 'in_progress') continue;
        const held = runningByMachine.get(entry.work_center.id);
        if (!held || entry.id > held.id) runningByMachine.set(entry.work_center.id, entry);
    }

    const downByMachine = new Map<number, MachineDowntimeLog>();
    for (const log of downtimeLogs?.data ?? []) {
        if (log.status === 'open') downByMachine.set(log.work_center.id, log);
    }

    const moldChangeByMachine = new Map<number, MoldChangeLog>();
    for (const log of moldChangeLogs?.data ?? []) {
        if (log.status === 'open') moldChangeByMachine.set(log.work_center.id, log);
    }

    const failedTally = (tallyEntries?.data ?? []).filter((e) => e.status === 'failed');

    const running = machines.filter((m) => runningByMachine.has(m.id) && !downByMachine.has(m.id));
    const down = machines.filter((m) => downByMachine.has(m.id));
    const changingMould = machines.filter((m) => !downByMachine.has(m.id) && moldChangeByMachine.has(m.id));
    const idle = machines.filter(
        (m) => !runningByMachine.has(m.id) && !downByMachine.has(m.id) && !moldChangeByMachine.has(m.id),
    );

    // The clock's own shift context, used to spot a batch still running from an
    // earlier shift or date — the case that needs a handover, not a new start.
    const today = productionDateFor(undefined);

    const rows = machines.map((machine) => {
        const entry = runningByMachine.get(machine.id) ?? null;
        const downLog = downByMachine.get(machine.id) ?? null;
        const moldLog = moldChangeByMachine.get(machine.id) ?? null;

        return {
            key: machine.id,
            machine,
            entry,
            downLog,
            moldLog,
            state: downLog ? 'down' : moldLog ? 'mould' : entry ? 'running' : 'idle',
            carryover: entry !== null && (entry.parent_entry_id != null || entry.production_date !== today),
        };
    });

    return (
        <>
            <Space style={{ marginBottom: 4, justifyContent: 'space-between', width: '100%' }} wrap>
                <Typography.Title level={3} style={{ margin: 0 }}>
                    Live Production Monitor
                </Typography.Title>
                <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                    Refreshes every {REFRESH_MS / 1000}s
                </Typography.Text>
            </Space>

            {/* Anything needing a person, said once and at the top. Silent when
                there is nothing to act on — a banner that is always present
                stops being read. */}
            {failedTally.length > 0 && (
                <Alert
                    type="error"
                    showIcon
                    style={{ marginBottom: 12 }}
                    message={`${failedTally.length} Tally voucher${failedTally.length === 1 ? '' : 's'} failed and need attention`}
                    description={
                        <>
                            Production is recorded and nothing is lost — the voucher can be retried from{' '}
                            <Link to="/tally-sync">Tally Sync</Link>.
                        </>
                    }
                />
            )}

            <Row gutter={[10, 10]} style={{ marginBottom: 16 }}>
                <Col xs={12} sm={8} md={6} lg={4}><Stat label="Machines" value={machines.length} /></Col>
                <Col xs={12} sm={8} md={6} lg={4}><Stat label="Running" value={running.length} tone="ok" to="/production/shift-production" /></Col>
                <Col xs={12} sm={8} md={6} lg={4}><Stat label="Idle" value={idle.length} to="/production/shift-production" /></Col>
                <Col xs={12} sm={8} md={6} lg={4}><Stat label="Down" value={down.length} tone={down.length > 0 ? 'stop' : undefined} /></Col>
                <Col xs={12} sm={8} md={6} lg={4}><Stat label="Mould change" value={changingMould.length} tone={changingMould.length > 0 ? 'warn' : undefined} /></Col>
                <Col xs={12} sm={8} md={6} lg={4}><Stat label="Failed Tally" value={failedTally.length} tone={failedTally.length > 0 ? 'stop' : undefined} to="/tally-sync" /></Col>
            </Row>

            <Row gutter={[10, 10]} style={{ marginBottom: 20 }}>
                <Col xs={12} sm={8} md={6}>
                    <Stat
                        label="Awaiting Plant Manager"
                        value={awaitingPm?.data.length ?? 0}
                        tone={(awaitingPm?.data.length ?? 0) > 0 ? 'warn' : undefined}
                        to="/production/approve-production"
                    />
                </Col>
                <Col xs={12} sm={8} md={6}>
                    <Stat
                        label="Awaiting Accountant"
                        value={awaitingAccounts?.data.length ?? 0}
                        tone={(awaitingAccounts?.data.length ?? 0) > 0 ? 'warn' : undefined}
                        to="/production/approve-production"
                    />
                </Col>
            </Row>

            <Typography.Title level={5}>Every machine</Typography.Title>
            <Table
                rowKey="key"
                size="small"
                pagination={false}
                scroll={{ x: 'max-content' }}
                dataSource={rows}
                columns={[
                    {
                        title: 'Machine',
                        render: (_, r) => <Typography.Text strong>{r.machine.name}</Typography.Text>,
                    },
                    {
                        title: 'State',
                        render: (_, r) => {
                            if (r.state === 'down') return <Tag color="error">Down — {r.downLog?.nature_of_problem}</Tag>;
                            if (r.state === 'mould') return <Tag color="warning">Mould change</Tag>;
                            if (r.state === 'running') return <Tag color="success">Running</Tag>;
                            return <Tag>Idle</Tag>;
                        },
                    },
                    {
                        title: 'Product',
                        render: (_, r) => (r.entry ? itemLabel(r.entry.item) : '—'),
                    },
                    {
                        title: 'Batch',
                        render: (_, r) => r.entry?.batch_number ?? '—',
                    },
                    {
                        title: 'Shift',
                        render: (_, r) => r.entry?.shift.name ?? '—',
                    },
                    {
                        title: 'Started',
                        render: (_, r) => (r.entry ? fmtTime(r.entry.created_at) : '—'),
                    },
                    {
                        title: 'Carryover',
                        render: (_, r) =>
                            r.carryover && r.entry ? (
                                // The case that needs a handover rather than a new
                                // start — worth naming the date it came from.
                                <Tag color="gold">from {r.entry.production_date}</Tag>
                            ) : (
                                '—'
                            ),
                    },
                    {
                        title: 'Cavities',
                        render: (_, r) => r.entry?.active_cavities ?? r.entry?.standard_cavities ?? '—',
                    },
                    {
                        title: 'Produced so far',
                        render: (_, r) => fmtQty(r.entry?.quantity_produced),
                    },
                ]}
            />

            <Typography.Text type="secondary" style={{ display: 'block', marginTop: 12, fontSize: 12 }}>
                “Produced so far” is blank while a batch is running — the quantity is entered at Complete Batch,
                so an in-progress batch has none yet rather than zero.
            </Typography.Text>
        </>
    );
}
