import { useQuery } from '@tanstack/react-query';
import { Skeleton, Table, Tag, Typography } from 'antd';
import dayjs from 'dayjs';
import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { hasModuleAccess } from '@/features/auth/permissions';
import { useAuthStore } from '@/features/auth/store';
import { getDashboardSummary } from '@/features/dashboard/api';
import type { DemandRow, IncomingStockRow, RecentSalesOrder } from '@/features/dashboard/types';
import { queueTileStyle } from '@/features/dashboard/queueTileStyle';
import { waitingOnYou } from '@/features/dashboard/waitingOnYou';
import {
    getFactoryDayBin,
    listActiveBatches,
    listMachineDowntimeLogs,
    listMoldChangeLogs,
    listShiftProductionEntries,
    listShifts,
    listWorkCenters,
} from '@/features/production/api';
import { activeShifts, currentShift, productionDateFor } from '@/features/production/shiftClock';
import type {
    MachineDowntimeLog,
    MoldChangeLog,
    Shift,
    ShiftProductionEntry,
    WorkCenter,
} from '@/features/production/types';
import { listAllTallySyncEntries } from '@/features/tally-sync/api';
import { ADOPTED_MODULES } from '@/lib/adoptedModules';
import { columnSorter, filterOptions, onFilterBy } from '@/lib/clientSort';
import { itemLabel } from '@/lib/itemLabel';
import { useDisplayStore } from '@/theme/store';
import '../dashboard.css';

/**
 * The office landing view: where does the factory stand right now, and what
 * is waiting on whom?
 *
 * Organized the way the factory itself is organized, not by software module:
 * the floor first (one day bin piped to every machine — DEC-20260807-006),
 * then the paper trail (PM desk → Accountant → Tally, the real approval
 * order), then the counting-house figures. Modules the factory has not
 * adopted (ADOPTED_MODULES in lib/adoptedModules, shared with the sidebar)
 * get no cell here either — though HRMS and CRM cells are pre-wired, waiting
 * on adoption.
 *
 * Every figure is read from an endpoint that already existed; this page adds
 * no API surface and writes nothing. Machine states are derived exactly as
 * the Live Monitor derives them so the two screens cannot disagree.
 */

/** Poll interval, matching the Shift Floor and Live Monitor. */
const REFRESH_MS = 20000;

const toMinutes = (time: string): number => {
    const [h, m] = time.split(':');
    return Number(h) * 60 + Number(m);
};

/** "06:00:00" → "06:00" */
const fmtHM = (time: string): string => time.slice(0, 5);

const fmtKg = (v: string): string => {
    const n = parseFloat(v);
    return Number.isNaN(n) ? '—' : `${n.toLocaleString('en-IN', { maximumFractionDigits: 1 })} kg`;
};

const fmtQty = (v: string): string => {
    const n = parseFloat(v);
    return Number.isNaN(n) ? '—' : n.toLocaleString('en-IN', { maximumFractionDigits: 2 });
};

const statusColor: Record<string, string> = {
    draft: 'default',
    confirmed: 'blue',
    sent: 'blue',
    partially_delivered: 'orange',
    partially_received: 'orange',
    completed: 'green',
    cancelled: 'red',
};

interface MachineRow {
    machine: WorkCenter;
    entry: ShiftProductionEntry | null;
    downLog: MachineDowntimeLog | null;
    moldLog: MoldChangeLog | null;
    state: 'running' | 'idle' | 'down' | 'mould';
    carryover: boolean;
}

const STATE_LABEL: Record<MachineRow['state'], string> = {
    running: 'Running',
    idle: 'Idle',
    down: 'Down',
    mould: 'Mould change',
};

function ShiftRail({ shifts, now }: { shifts: Shift[]; now: Date }) {
    const ordered = [...shifts].sort((a, b) => toMinutes(a.start_time) - toMinutes(b.start_time));
    if (ordered.length === 0) return null;

    const railStart = toMinutes(ordered[0].start_time);
    const durations = ordered.map(
        (s) => (toMinutes(s.end_time) - toMinutes(s.start_time) + 1440) % 1440 || 1440 / ordered.length,
    );
    const total = durations.reduce((a, b) => a + b, 0);
    const active = currentShift(ordered, now);
    const nowPct = total > 0 ? (((now.getHours() * 60 + now.getMinutes() - railStart + 1440) % 1440) / total) * 100 : 0;

    return (
        <div className="dash-shiftrail">
            <div className="dash-eyebrow" style={{ marginBottom: 4 }}>
                Shifts
            </div>
            <div className="dash-shiftrail-track">
                {ordered.map((shift, i) => (
                    <div
                        key={shift.id}
                        className={`dash-shiftseg${active?.id === shift.id ? ' is-active' : ''}`}
                        style={{ width: `${(durations[i] / total) * 100}%` }}
                    >
                        <span className="dash-shiftseg-name">{shift.name}</span>
                        <span className="dash-shiftseg-time">
                            {fmtHM(shift.start_time)}–{fmtHM(shift.end_time)}
                        </span>
                    </div>
                ))}
                {nowPct >= 0 && nowPct <= 100 && <div className="dash-now" style={{ left: `${nowPct}%` }} />}
            </div>
        </div>
    );
}

function MachineCell({ row }: { row: MachineRow }) {
    const title =
        row.state === 'down'
            ? `${row.machine.name} — down: ${row.downLog?.nature_of_problem ?? ''}`
            : row.entry
              ? `${row.machine.name} — ${itemLabel(row.entry.item)}`
              : row.machine.name;

    return (
        <Link to="/production/live-monitor" className={`dash-cell is-${row.state}`} title={title}>
            <span className="dash-cell-code">
                <span className="dash-lamp" />
                <span className="dash-cell-code-name">{row.machine.name}</span>
            </span>
            <div className="dash-cell-state">{STATE_LABEL[row.state]}</div>
            <div className="dash-cell-product">{row.entry ? itemLabel(row.entry.item) : '—'}</div>
            {row.entry && <div className="dash-cell-batch">{row.entry.batch_number}</div>}
            {row.carryover && row.entry && (
                <div className="dash-cell-carryover">from {row.entry.production_date}</div>
            )}
        </Link>
    );
}

function LedgerCell({ figure, label, to, warn }: { figure: string; label: string; to: string; warn?: boolean }) {
    return (
        <Link to={to} className={`dash-ledger-cell${warn ? ' is-warn' : ''}`}>
            <div className="dash-ledger-figure">{figure}</div>
            <div className="dash-ledger-label">{label}</div>
        </Link>
    );
}

export default function DashboardPage() {
    const user = useAuthStore((state) => state.user);
    const themeMode = useDisplayStore((state) => state.mode);
    // Same double gate as the sidebar: the factory must have adopted the
    // module AND the user must hold its permission. Cells for HRMS and CRM
    // are already wired below — the day either module joins ADOPTED_MODULES
    // for the sidebar, its dashboard cells appear in the same commit.
    const inUse = (module: string) => ADOPTED_MODULES.has(module) && hasModuleAccess(user, module);
    const canProduction = inUse('production');
    const canTally = inUse('tally-sync');

    // Drives the shift rail's now-marker; a minute's precision is plenty.
    const [now, setNow] = useState(() => new Date());
    useEffect(() => {
        const timer = window.setInterval(() => setNow(new Date()), 30000);
        return () => window.clearInterval(timer);
    }, []);

    const { data: summary, isLoading } = useQuery({
        queryKey: ['dashboard', 'summary'],
        queryFn: getDashboardSummary,
    });
    const { data: shifts } = useQuery({
        // Distinct key from the unfiltered admin/history query — the two
        // response shapes must never share a cache entry.
        queryKey: ['production', 'shifts', 'active'],
        queryFn: () => listShifts(true),
        enabled: canProduction,
    });
    const { data: workCenters } = useQuery({
        queryKey: ['production', 'work-centers', 'active'],
        queryFn: () => listWorkCenters(true),
        enabled: canProduction,
    });
    const { data: activeBatches } = useQuery({
        queryKey: ['production', 'active-batches'],
        queryFn: listActiveBatches,
        refetchInterval: REFRESH_MS,
        enabled: canProduction,
    });
    const { data: downtimeLogs } = useQuery({
        queryKey: ['production', 'machine-downtime-logs'],
        queryFn: listMachineDowntimeLogs,
        refetchInterval: REFRESH_MS,
        enabled: canProduction,
    });
    const { data: moldChangeLogs } = useQuery({
        queryKey: ['production', 'mold-change-logs'],
        queryFn: listMoldChangeLogs,
        refetchInterval: REFRESH_MS,
        enabled: canProduction,
    });
    const { data: dayBin } = useQuery({
        queryKey: ['production', 'factory-day-bin'],
        queryFn: getFactoryDayBin,
        refetchInterval: REFRESH_MS * 3,
        enabled: canProduction,
    });
    const { data: awaitingPm } = useQuery({
        queryKey: ['production', 'shift-production-entries', 'pending'],
        queryFn: () => listShiftProductionEntries('pending'),
        refetchInterval: REFRESH_MS,
        enabled: canProduction,
    });
    const { data: awaitingAccounts } = useQuery({
        queryKey: ['production', 'shift-production-entries', 'pm_approved'],
        queryFn: () => listShiftProductionEntries('pm_approved'),
        refetchInterval: REFRESH_MS,
        enabled: canProduction,
    });
    const { data: tallyQueue } = useQuery({
        queryKey: ['tally-sync', 'entries', 'all'],
        queryFn: listAllTallySyncEntries,
        refetchInterval: REFRESH_MS,
        enabled: canTally,
    });

    // The operational contract twice over: the query asks the server for
    // active shifts only, and activeShifts() re-asserts it client-side.
    // Live still carries the deactivated Morning/Afternoon/Night rows from
    // the pre-rename era (DEC-20260806-007, seeder incident PR #125) —
    // rendering the raw list drew six segments on the live rail.
    const shiftList = activeShifts(shifts?.data ?? []);
    const activeShift = currentShift(shiftList, now);
    const productionDate = productionDateFor(activeShift, now);

    // The factory's own shift length (from shift data, like everything else
    // here), for translating run-time estimates into shifts. Null without
    // production access — hours are shown alone then.
    const shiftMinutes = shiftList.map((s) => ((toMinutes(s.end_time) - toMinutes(s.start_time) + 1440) % 1440) || 480);
    const avgShiftHours =
        shiftMinutes.length > 0 ? shiftMinutes.reduce((a, b) => a + b, 0) / shiftMinutes.length / 60 : null;

    // Same derivation as the Live Monitor: newest in-progress batch per
    // machine, across all shifts and dates.
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

    const machineRows: MachineRow[] = (workCenters?.data ?? [])
        .filter((m) => m.is_active)
        .sort((a, b) => a.name.localeCompare(b.name, undefined, { numeric: true }))
        .map((machine) => {
            const entry = runningByMachine.get(machine.id) ?? null;
            const downLog = downByMachine.get(machine.id) ?? null;
            const moldLog = moldChangeByMachine.get(machine.id) ?? null;
            return {
                machine,
                entry,
                downLog,
                moldLog,
                state: downLog ? 'down' : moldLog ? 'mould' : entry ? 'running' : 'idle',
                carryover: entry !== null && (entry.parent_entry_id != null || entry.production_date !== productionDate),
            };
        });

    const runningCount = machineRows.filter((r) => r.state === 'running').length;

    const tallyEntries = tallyQueue?.entries ?? [];
    const tallyHeld = tallyEntries.filter((e) => e.status === 'pending' && e.hold).length;
    const tallyQueued = tallyEntries.filter((e) => e.status === 'pending' && !e.hold).length;
    const tallyFailed = tallyEntries.filter((e) => e.status === 'failed').length;
    const tallyTruncated = tallyQueue !== undefined && tallyQueue.total > tallyEntries.length;

    const pmCount = awaitingPm?.meta.total ?? 0;
    const accountsCount = awaitingAccounts?.meta.total ?? 0;

    const binMaterials = (dayBin?.summary ?? []).filter((row) => parseFloat(row.bin_kg) !== 0).slice(0, 3);

    /**
     * WHAT THIS LOGIN OWES, ABOVE EVERYTHING ELSE (chapter 1 §1).
     *
     * A key is offered only when its figure is this reader's to see, because
     * an ABSENT count means "not yours" and a zero means "yours, and clear" —
     * the strip draws those two differently and must not be handed one for
     * the other. The server has already gated the summary blocks; the two
     * production figures ride the same `canProduction` gate their band does.
     */
    const counts = {
        ...(summary?.inventory && {
            issue: summary.inventory.material_requests_to_issue,
            fulfil: summary.inventory.order_lines_awaiting_store,
        }),
        ...(canProduction && { pm: pmCount, accounts: accountsCount }),
        ...(summary?.procurement && { requisitions: summary.procurement.pending_requisitions }),
        ...(summary?.quality && { ncrs: summary.quality.open_ncrs }),
        ...(canTally && { tally: tallyFailed }),
    };
    const tiles = waitingOnYou(
        counts,
        (user?.roles ?? []).map((role) => role.name),
    );
    // The three tone colours follow light/dark — see queueTileStyle().
    const tileStyles = useMemo(() => queueTileStyle(themeMode), [themeMode]);

    return (
        <div className="dash">
            <div className="dash-masthead">
                <div>
                    <div className="dash-eyebrow">Production day</div>
                    <div className="dash-date">{dayjs(productionDate).format('DD MMM YYYY').toUpperCase()}</div>
                    <div className="dash-masthead-sub">
                        {activeShift
                            ? `${activeShift.name} on the floor · figures refresh every ${REFRESH_MS / 1000}s`
                            : `Figures refresh every ${REFRESH_MS / 1000}s`}
                    </div>
                </div>
                {canProduction && shiftList.length > 0 && <ShiftRail shifts={shiftList} now={now} />}
            </div>

            {tiles.length > 0 && (
                <section className="dash-band">
                    <div className="dash-eyebrow">Waiting on you</div>
                    <div className="dash-queue">
                        {tiles.map((tile) => {
                            const tone = tileStyles[tile.tone];

                            return (
                                <Link
                                    key={tile.key}
                                    to={tile.to}
                                    className="dash-tile"
                                    style={{ background: tone.background, borderColor: tone.borderColor }}
                                >
                                    <span className="dash-tile-count" style={{ color: tone.figure }}>
                                        {tile.count}
                                    </span>
                                    <span className="dash-tile-label" style={{ color: tone.label }}>
                                        {tile.label}
                                    </span>
                                </Link>
                            );
                        })}
                    </div>
                </section>
            )}

            {canProduction && (
                <section className="dash-band">
                    <div className="dash-eyebrow">
                        The floor · {runningCount} of {machineRows.length} machines running
                    </div>
                    <div className="dash-floor">
                        <Link to="/production/day-bin" className="dash-bin">
                            <span className="dash-eyebrow" style={{ color: 'var(--dash-ink)' }}>
                                Day bin
                            </span>
                            {binMaterials.length > 0 ? (
                                binMaterials.map((row) => (
                                    <span className="dash-bin-material" key={row.item_id} title={itemLabel(row.item)}>
                                        <span className="dash-bin-material-name">{row.item.name}</span>
                                        <span className="dash-bin-kg">{fmtKg(row.bin_kg)}</span>
                                    </span>
                                ))
                            ) : (
                                <span className="dash-bin-material">
                                    <span className="dash-bin-material-name">
                                        {dayBin?.warehouse ? 'Empty' : 'Not configured'}
                                    </span>
                                </span>
                            )}
                            {binMaterials.length > 0 && <span className="dash-bin-note">Estimate — drifts</span>}
                        </Link>
                        <div className="dash-pipe" aria-hidden="true" />
                        <div className="dash-machines">
                            {machineRows.map((row) => (
                                <MachineCell key={row.machine.id} row={row} />
                            ))}
                        </div>
                    </div>
                    <div className="dash-floor-foot">
                        One common resin input feeds every machine; the day-bin figure is a running estimate, never a
                        count — stock truth is the Tally reconcile. Detail on the{' '}
                        <Link to="/production/live-monitor">Live Monitor</Link>.
                    </div>
                </section>
            )}

            {(canProduction || canTally) && (
                <section className="dash-band">
                    <div className="dash-eyebrow">Paper trail · batch → Tally</div>
                    <div className="dash-chain">
                        {canProduction && (
                            <>
                                <Link
                                    to="/production/approve-production"
                                    className={`dash-stage${pmCount > 0 ? ' has-work' : ''}`}
                                >
                                    <span className="dash-stage-count">{pmCount}</span>
                                    <span className="dash-stage-label">Awaiting Plant Manager</span>
                                </Link>
                                <span className="dash-chain-arrow" aria-hidden="true">
                                    →
                                </span>
                                <Link
                                    to="/production/approve-production"
                                    className={`dash-stage${accountsCount > 0 ? ' has-work' : ''}`}
                                >
                                    <span className="dash-stage-count">{accountsCount}</span>
                                    <span className="dash-stage-label">Awaiting Accountant</span>
                                </Link>
                                {canTally && (
                                    <span className="dash-chain-arrow" aria-hidden="true">
                                        →
                                    </span>
                                )}
                            </>
                        )}
                        {canTally && (
                            <Link to="/tally-sync" className={`dash-stage${tallyFailed > 0 ? ' has-failure' : ''}`}>
                                <span className="dash-stage-label">Tally vouchers</span>
                                <span className="dash-stage-detail">
                                    held {tallyHeld} · queued {tallyQueued} ·{' '}
                                    <span className={tallyFailed > 0 ? 'is-failed' : ''}>failed {tallyFailed}</span>
                                </span>
                            </Link>
                        )}
                    </div>
                    {tallyFailed > 0 && (
                        <div className="dash-floor-foot">
                            Production is recorded and nothing is lost — retry the failed voucher
                            {tallyFailed === 1 ? '' : 's'} from <Link to="/tally-sync">Tally Sync</Link>.
                        </div>
                    )}
                    {tallyTruncated && (
                        <div className="dash-floor-foot">
                            Counting the newest {tallyEntries.length} of {tallyQueue?.total} vouchers — the full queue
                            is on <Link to="/tally-sync">Tally Sync</Link>.
                        </div>
                    )}
                </section>
            )}

            {inUse('sales') && summary?.demand && summary.demand.length > 0 && (
                <section className="dash-band">
                    <div className="dash-eyebrow">Order book · open orders against the shelf</div>
                    <div className="dash-recent">
                        <Table<DemandRow>
                            rowKey={(r) => `${r.sales_order_id}-${r.item}`}
                            size="small"
                            pagination={false}
                            scroll={{ x: 'max-content' }}
                            dataSource={summary.demand}
                            // The whole order book is here (no pager): honest
                            // client sorters, undated promises last.
                            columns={[
                                { title: 'Product', dataIndex: 'item', sorter: columnSorter((r: DemandRow) => r.item, 'text') },
                                { title: 'Customer', dataIndex: 'customer', sorter: columnSorter((r: DemandRow) => r.customer, 'text') },
                                {
                                    title: 'Promised',
                                    dataIndex: 'expected_date',
                                    sorter: columnSorter((r: DemandRow) => r.expected_date, 'date'),
                                    render: (d: string | null) => d ?? '—',
                                },
                                {
                                    title: 'Ordered',
                                    dataIndex: 'ordered',
                                    align: 'right',
                                    sorter: columnSorter((r: DemandRow) => r.ordered, 'number'),
                                    render: fmtQty,
                                },
                                {
                                    title: 'Delivered',
                                    dataIndex: 'delivered',
                                    align: 'right',
                                    sorter: columnSorter((r: DemandRow) => r.delivered, 'number'),
                                    render: fmtQty,
                                },
                                {
                                    title: 'In stock',
                                    dataIndex: 'on_hand',
                                    align: 'right',
                                    sorter: columnSorter((r: DemandRow) => r.on_hand, 'number'),
                                    render: fmtQty,
                                },
                                {
                                    title: 'To produce',
                                    dataIndex: 'to_produce',
                                    align: 'right',
                                    sorter: columnSorter((r: DemandRow) => r.to_produce, 'number'),
                                    render: (v: string) =>
                                        parseFloat(v) > 0 ? (
                                            <Typography.Text strong>{fmtQty(v)}</Typography.Text>
                                        ) : (
                                            '—'
                                        ),
                                },
                                {
                                    title: 'Run time at standard',
                                    render: (_: unknown, r: DemandRow) => {
                                        if (parseFloat(r.to_produce) <= 0) return <Tag color="green">covered by stock</Tag>;
                                        if (r.standard === 'none')
                                            return <Typography.Text type="secondary">no standard recorded</Typography.Text>;
                                        if (r.standard === 'ambiguous')
                                            return (
                                                <Typography.Text type="secondary">standard variants disagree</Typography.Text>
                                            );
                                        if (r.hours_at_standard === null) return '—';
                                        const shiftsNeeded =
                                            avgShiftHours !== null ? r.hours_at_standard / avgShiftHours : null;
                                        return (
                                            <>
                                                ≈ {r.hours_at_standard} h
                                                {shiftsNeeded !== null && ` · ${shiftsNeeded.toFixed(1)} shifts`}
                                            </>
                                        );
                                    },
                                },
                            ]}
                        />
                    </div>
                    <div className="dash-floor-foot">
                        Run time is the shortfall at the product's standard cycle time — no downtime, no mould changes,
                        no machine choice. A product without a recorded standard shows none rather than a guess.
                    </div>
                </section>
            )}

            {inUse('procurement') && summary?.incoming_stock && summary.incoming_stock.length > 0 && (
                <section className="dash-band">
                    <div className="dash-eyebrow">Stock coming in</div>
                    <div className="dash-recent">
                        <Table<IncomingStockRow>
                            rowKey="id"
                            size="small"
                            pagination={false}
                            scroll={{ x: 'max-content' }}
                            dataSource={summary.incoming_stock}
                            columns={[
                                { title: 'Vendor', dataIndex: 'vendor', sorter: columnSorter((r: IncomingStockRow) => r.vendor, 'text') },
                                { title: 'Items', dataIndex: 'items' },
                                {
                                    title: 'Expected',
                                    dataIndex: 'expected_date',
                                    sorter: columnSorter((r: IncomingStockRow) => r.expected_date, 'date'),
                                    render: (d: string | null) => d ?? '—',
                                },
                                {
                                    title: 'Status',
                                    dataIndex: 'status',
                                    filters: filterOptions(summary.incoming_stock, (r) => r.status),
                                    onFilter: onFilterBy((r: IncomingStockRow) => r.status),
                                    render: (status: string) => (
                                        <Tag color={statusColor[status] ?? 'default'}>{status}</Tag>
                                    ),
                                },
                            ]}
                        />
                    </div>
                </section>
            )}

            <section className="dash-band">
                <div className="dash-eyebrow">Office</div>
                {isLoading || !summary ? (
                    <Skeleton active paragraph={{ rows: 2 }} style={{ marginTop: 12 }} />
                ) : (
                    <div className="dash-ledger">
                        {inUse('inventory') && summary.inventory && (
                            <LedgerCell
                                figure={String(summary.inventory.low_stock_items)}
                                label="Low stock items"
                                to="/inventory/stock"
                                warn={summary.inventory.low_stock_items > 0}
                            />
                        )}
                        {inUse('procurement') && summary.procurement && (
                            <>
                                <LedgerCell
                                    figure={String(summary.procurement.open_purchase_orders)}
                                    label="Open purchase orders"
                                    to="/procurement/purchase-orders"
                                />
                                <LedgerCell
                                    figure={String(summary.procurement.pending_requisitions)}
                                    label="Requisitions to approve"
                                    // ?status=draft: the tile counts drafts, so
                                    // the click lands on exactly the rows it
                                    // counted — the figure is checkable.
                                    to="/procurement/purchase-requisitions?status=draft"
                                />
                            </>
                        )}
                        {inUse('sales') && summary.sales && (
                            <>
                                <LedgerCell
                                    figure={String(summary.sales.open_sales_orders)}
                                    label="Open sales orders"
                                    to="/sales/sales-orders"
                                />
                                <LedgerCell
                                    figure={String(summary.sales.orders_awaiting_delivery)}
                                    label="Awaiting delivery"
                                    to="/sales/deliveries"
                                />
                                <LedgerCell
                                    figure={Number(summary.sales.receivables_outstanding).toLocaleString('en-IN', {
                                        style: 'currency',
                                        currency: 'INR',
                                        maximumFractionDigits: 0,
                                    })}
                                    label="Receivables outstanding"
                                    to="/finance/reports"
                                />
                            </>
                        )}
                        {inUse('quality') && summary.quality && (
                            <>
                                <LedgerCell
                                    figure={String(summary.quality.open_ncrs)}
                                    label="Open NCRs"
                                    to="/quality/ncrs"
                                    warn={summary.quality.open_ncrs > 0}
                                />
                                <LedgerCell
                                    figure={String(summary.quality.open_capas)}
                                    label="Open CAPAs"
                                    to="/quality/capas"
                                />
                            </>
                        )}
                        {inUse('maintenance') && summary.maintenance && (
                            <LedgerCell
                                figure={String(summary.maintenance.open_work_orders)}
                                label="Maintenance work orders"
                                to="/maintenance/work-orders"
                            />
                        )}
                        {/* Pre-wired for adoption day: these render only once
                            'hrms' / 'crm' join ADOPTED_MODULES for the sidebar,
                            so both surfaces switch on in the same one-line edit. */}
                        {inUse('hrms') && summary.hrms && (
                            <LedgerCell
                                figure={String(summary.hrms.pending_leave_requests)}
                                label="Pending leave requests"
                                to="/hrms/leave-requests"
                            />
                        )}
                        {inUse('crm') && summary.crm && (
                            <>
                                <LedgerCell
                                    figure={String(summary.crm.open_leads)}
                                    label="Open leads"
                                    to="/crm/leads"
                                />
                                <LedgerCell
                                    figure={String(summary.crm.open_opportunities)}
                                    label="Open opportunities"
                                    to="/crm/opportunities"
                                />
                            </>
                        )}
                    </div>
                )}
            </section>

            {inUse('sales') && summary?.recent_sales_orders && summary.recent_sales_orders.length > 0 && (
                <section className="dash-band">
                    <div className="dash-eyebrow">Recent sales orders</div>
                    <div className="dash-recent">
                        <Table<RecentSalesOrder>
                            rowKey="id"
                            size="small"
                            pagination={false}
                            scroll={{ x: 'max-content' }}
                            dataSource={summary.recent_sales_orders}
                            columns={[
                                { title: 'Customer', dataIndex: 'customer', sorter: columnSorter((r: RecentSalesOrder) => r.customer, 'text') },
                                {
                                    title: 'Order date',
                                    dataIndex: 'order_date',
                                    sorter: columnSorter((r: RecentSalesOrder) => r.order_date, 'date'),
                                },
                                {
                                    title: 'Status',
                                    dataIndex: 'status',
                                    filters: filterOptions(summary.recent_sales_orders, (r) => r.status),
                                    onFilter: onFilterBy((r: RecentSalesOrder) => r.status),
                                    render: (status: string) => (
                                        <Tag color={statusColor[status] ?? 'default'}>{status}</Tag>
                                    ),
                                },
                            ]}
                        />
                    </div>
                </section>
            )}

            {!canProduction && !summary && isLoading && <Skeleton active paragraph={{ rows: 6 }} />}
            {machineRows.length === 0 && canProduction && workCenters !== undefined && (
                <Typography.Paragraph type="secondary" style={{ marginTop: 12 }}>
                    No active machines yet — set them up in{' '}
                    <Link to="/production/configuration">Production Configuration</Link>.
                </Typography.Paragraph>
            )}
        </div>
    );
}
