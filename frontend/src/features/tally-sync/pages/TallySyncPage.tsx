import { useMemo, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Button, Modal, Space, Table, Tag, Tooltip, Typography } from 'antd';
import { Link } from 'react-router-dom';
import { listAllTallySyncEntries, releaseTallySyncEntry, retryTallySyncEntry } from '@/features/tally-sync/api';
import type { TallySyncEntry, TallySyncStatus } from '@/features/tally-sync/types';

const statusColor: Record<TallySyncStatus, string> = {
    pending: 'default',
    synced: 'green',
    failed: 'red',
};

const statusLabel: Record<TallySyncStatus, string> = {
    pending: 'Waiting for agent',
    synced: 'In Tally',
    failed: 'FAILED',
};

/**
 * Failed first, always. Everything else on this page — the count in the red
 * strip, what "Resync all failed" picks up — reads the same order, so a
 * rejection can never be pushed below the fold by a day of successful posts.
 */
const statusRank: Record<TallySyncStatus, number> = { failed: 0, pending: 1, synced: 2 };

/** One stock line of a production voucher, as the payload carries it. */
type VoucherStockLine = { item: string; quantity: string; godown?: string | null };

/**
 * The produced[]/consumed[] arrays out of a production voucher's payload —
 * batch and consolidated shift vouchers both carry them — or null for the
 * voucher types that don't (sales, receipt/delivery notes, journals), which
 * fall back to the raw payload view.
 */
function voucherStockLines(entry: TallySyncEntry, key: 'produced' | 'consumed'): VoucherStockLine[] | null {
    const value = entry.payload?.[key];
    if (!Array.isArray(value)) {
        return null;
    }

    const lines = value.filter(
        (line): line is VoucherStockLine =>
            typeof line === 'object' && line !== null
            && typeof (line as VoucherStockLine).item === 'string'
            && typeof (line as VoucherStockLine).quantity === 'string',
    );

    return lines.length === value.length ? lines : null;
}

/** A string field out of the voucher payload, or null if it isn't usable. */
function payloadText(entry: TallySyncEntry, key: string): string | null {
    const value = entry.payload?.[key];

    return typeof value === 'string' && value !== '' ? value : null;
}

/**
 * The number staff will search for in Tally. Mirrors the backend's
 * TallySyncEntry::voucherNumber() fallback exactly — same answer on both
 * sides of the wire, so what the screen says matches what the log says.
 */
function voucherNumber(entry: TallySyncEntry): string {
    return payloadText(entry, 'voucher_number') ?? `#${entry.id}`;
}

/**
 * Server-stamped instants (synced_at, delivered_at, created_at) converted to
 * the viewer's clock. Deliberately NOT lib/datetime's formatDateTime: that one
 * reads the ISO string as written, which is right for a wall-clock time the
 * factory typed in, and wrong here — these are stamped `now()` in UTC, and
 * slicing them would show an IST user a sync that happened at 14:30 as 09:00.
 */
function instant(value: string | null | undefined): string {
    if (!value) return '—';
    const parsed = new Date(value);

    return Number.isNaN(parsed.getTime()) ? value : parsed.toLocaleString('en-IN', {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
}

/** The most recent of a set of ISO timestamps — all share the server's offset. */
function latest(values: (string | null | undefined)[]): string | null {
    const stamps = values.filter((value): value is string => typeof value === 'string' && value !== '');

    return stamps.length > 0 ? stamps.reduce((a, b) => (a > b ? a : b)) : null;
}

/**
 * What the API said when a resync was refused.
 *
 * The 422 from the retry guard is the useful answer, not an error to
 * genericise: "This voucher is already in Tally as SPE-42 — check Tally
 * before anything else." Shown word for word.
 */
function resyncMessage(error: unknown): string {
    const response = (error as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } })
        ?.response;

    return (
        response?.data?.errors?.entry?.[0]
        ?? response?.data?.message
        ?? 'Could not reach the ERP to resync. Check the connection and try again.'
    );
}

interface ResyncOutcome {
    ok: boolean;
    message: string;
}

export default function TallySyncPage() {
    const queryClient = useQueryClient();

    const { data, isLoading, isError, isFetching, refetch } = useQuery({
        queryKey: ['tally-sync', 'entries', 'all'],
        queryFn: () => listAllTallySyncEntries(),
    });

    // Keyed by entry id so the answer stays on screen next to the row it
    // belongs to — a toast that vanishes in three seconds is no use to
    // someone who has to go and look something up in Tally.
    const [outcomes, setOutcomes] = useState<Record<number, ResyncOutcome>>({});
    const [retryingId, setRetryingId] = useState<number | null>(null);
    const [releasingId, setReleasingId] = useState<number | null>(null);
    const [resyncingAll, setResyncingAll] = useState(false);
    const [report, setReport] = useState<{ id: number; voucher: string; ok: boolean; message: string }[] | null>(null);
    const [viewing, setViewing] = useState<TallySyncEntry | null>(null);

    const entries = useMemo(
        () => [...(data?.entries ?? [])].sort(
            (a, b) => statusRank[a.status] - statusRank[b.status] || b.id - a.id,
        ),
        [data],
    );

    const failed = useMemo(() => entries.filter((entry) => entry.status === 'failed'), [entries]);
    const pendingCount = entries.filter((entry) => entry.status === 'pending').length;

    const lastSynced = useMemo(() => latest(entries.map((entry) => entry.synced_at)), [entries]);
    const lastCollected = useMemo(() => latest(entries.map((entry) => entry.delivered_at)), [entries]);

    // The honesty line. If we are holding fewer rows than the server has, the
    // failure count below is a floor, not a total, and the page must say so
    // rather than hand out an all-clear it cannot stand behind.
    const total = data?.total ?? 0;
    const truncated = total > entries.length;

    const busy = retryingId !== null || releasingId !== null || resyncingAll;

    /**
     * The all-clear, worded to cover exactly what was actually looked at.
     * Never shown when the fetch failed — see the error alert below: an empty
     * `entries` because the request 403'd looks identical to an empty queue,
     * and a green tick over the first of those is a lie the floor would act on.
     */
    const allClear = entries.length === 0
        ? 'No vouchers queued yet — nothing has been sent to Tally so far.'
        : truncated
            ? `No failed vouchers among the ${entries.length} most recent`
            : 'No failed vouchers — everything the ERP has sent, Tally has taken.';

    async function resyncOne(entry: TallySyncEntry) {
        setRetryingId(entry.id);
        try {
            await retryTallySyncEntry(entry.id);
            setOutcomes((prev) => ({
                ...prev,
                [entry.id]: { ok: true, message: 'Queued again — the agent will pick it up on its next check.' },
            }));
        } catch (error) {
            setOutcomes((prev) => ({ ...prev, [entry.id]: { ok: false, message: resyncMessage(error) } }));
        } finally {
            setRetryingId(null);
            await queryClient.invalidateQueries({ queryKey: ['tally-sync', 'entries'] });
        }
    }

    /**
     * The accountant's override on a held shift voucher: skip the rest of
     * the shift-end/quiet-period wait and let the agent collect it on its
     * next check. The 422 for "not actually held any more" is shown word
     * for word, same as the resync refusals.
     */
    async function releaseOne(entry: TallySyncEntry) {
        setReleasingId(entry.id);
        try {
            await releaseTallySyncEntry(entry.id);
            setOutcomes((prev) => ({
                ...prev,
                [entry.id]: { ok: true, message: 'Released — the agent will collect it on its next check.' },
            }));
        } catch (error) {
            setOutcomes((prev) => ({ ...prev, [entry.id]: { ok: false, message: resyncMessage(error) } }));
        } finally {
            setReleasingId(null);
            await queryClient.invalidateQueries({ queryKey: ['tally-sync', 'entries'] });
        }
    }

    /**
     * Retry every failed voucher, ONE AT A TIME.
     *
     * Three things this deliberately does not do: fire them in parallel (the
     * agent and Tally are one desktop machine at the factory, not a farm);
     * stop at the first refusal (a 422 on one voucher is a normal answer and
     * must not strand the other nine); or refetch between vouchers (the list
     * would re-sort underneath the loop). The target list is snapshotted
     * first and the cache is invalidated once, at the end.
     */
    async function resyncAllFailed() {
        const targets = failed;
        if (targets.length === 0) return;

        setResyncingAll(true);
        const results: { id: number; voucher: string; ok: boolean; message: string }[] = [];
        const collected: Record<number, ResyncOutcome> = {};

        for (const entry of targets) {
            setRetryingId(entry.id);
            try {
                await retryTallySyncEntry(entry.id);
                const message = 'Queued again — the agent will pick it up on its next check.';
                collected[entry.id] = { ok: true, message };
                results.push({ id: entry.id, voucher: voucherNumber(entry), ok: true, message });
            } catch (error) {
                const message = resyncMessage(error);
                collected[entry.id] = { ok: false, message };
                results.push({ id: entry.id, voucher: voucherNumber(entry), ok: false, message });
            }
        }

        setRetryingId(null);
        setOutcomes((prev) => ({ ...prev, ...collected }));
        setReport(results);
        setResyncingAll(false);
        await queryClient.invalidateQueries({ queryKey: ['tally-sync', 'entries'] });
    }

    return (
        <>
            {/* Failed rows carry a red wash as well as a red tag — scanned from
                across the room, colour is what gets noticed, not wording. */}
            <style>{'.tally-sync-failed-row > td { background: #fff1f0 !important; }'}</style>

            <Space style={{ width: '100%', justifyContent: 'space-between', alignItems: 'flex-start' }}>
                <Typography.Title level={3} style={{ marginBottom: 8 }}>
                    Tally Sync
                </Typography.Title>
                <Button onClick={() => refetch()} loading={isFetching && !busy}>
                    Refresh
                </Button>
            </Space>

            {isError && (
                <Alert
                    type="error"
                    showIcon
                    style={{ marginBottom: 16 }}
                    message={<strong>Could not load the sync queue</strong>}
                    description={
                        <Space direction="vertical" size={8} style={{ width: '100%' }}>
                            <span>
                                This page cannot tell you whether any vouchers failed — an empty list below means
                                nothing was read, NOT that everything is in Tally. Check the connection, or that
                                this login has Tally Sync access, and try again.
                            </span>
                            <Button danger type="primary" loading={isFetching} onClick={() => refetch()}>
                                Try again
                            </Button>
                        </Space>
                    }
                />
            )}

            {failed.length > 0 && (
                <Alert
                    type="error"
                    showIcon
                    style={{ marginBottom: 16 }}
                    message={
                        <strong>
                            {failed.length === 1
                                ? '1 voucher failed — Tally has not received it'
                                : `${failed.length} vouchers failed — Tally has not received them`}
                        </strong>
                    }
                    description={
                        <Space direction="vertical" size={8} style={{ width: '100%' }}>
                            <span>
                                These numbers are in the ERP but NOT in the accountant's books. Read the reason on
                                each row below, fix it, then Resync. Failed vouchers are listed first.
                            </span>
                            {truncated && (
                                <span>
                                    Showing the most recent {entries.length} of {total} vouchers — there may be older
                                    failures than the ones counted here.
                                </span>
                            )}
                            <Button danger type="primary" loading={resyncingAll} onClick={resyncAllFailed}>
                                Resync all {failed.length} failed
                            </Button>
                        </Space>
                    }
                />
            )}

            {failed.length === 0 && !isLoading && !isError && (
                <Alert
                    type={truncated ? 'info' : 'success'}
                    showIcon
                    style={{ marginBottom: 16 }}
                    message={allClear}
                    description={
                        <>
                            {/* An all-clear is only honest if it covers everything.
                                When it doesn't, say so rather than let the green
                                tick vouch for vouchers nobody looked at. */}
                            {truncated && (
                                <div>
                                    This page is holding the most recent {entries.length} of {total} vouchers — older
                                    ones have not been checked.
                                </div>
                            )}
                            {pendingCount > 0 && (
                                <div>
                                    {pendingCount} voucher{pendingCount === 1 ? '' : 's'} still waiting for the agent to
                                    collect.
                                </div>
                            )}
                        </>
                    }
                />
            )}

            <Typography.Paragraph type="secondary">
                Vouchers reach Tally through the desktop agent at the factory — nothing here talks to Tally directly.
                If nothing is syncing, that machine needs to be switched on with Tally open on the right company.
                <br />
                {isError ? (
                    // "—" here would read as "the agent has never delivered
                    // anything", which is a different and much more alarming
                    // claim than "this page could not find out".
                    <span>Last delivery time unknown — the queue could not be read.</span>
                ) : (
                    <>
                        Last voucher accepted by Tally: <strong>{instant(lastSynced)}</strong>
                        {' · '}
                        Agent last collected work: <strong>{instant(lastCollected)}</strong>
                    </>
                )}
            </Typography.Paragraph>

            <Table<TallySyncEntry>
                scroll={{ x: 'max-content' }}
                rowKey="id"
                loading={isLoading}
                dataSource={entries}
                pagination={{ defaultPageSize: 25, showSizeChanger: true }}
                rowClassName={(row) => (row.status === 'failed' ? 'tally-sync-failed-row' : '')}
                columns={[
                    {
                        title: 'Voucher',
                        render: (_, row) => (
                            <Space direction="vertical" size={0}>
                                <strong>{voucherNumber(row)}</strong>
                                <Typography.Text type="secondary">{row.tally_voucher_type}</Typography.Text>
                            </Space>
                        ),
                    },
                    {
                        title: 'Batch / Source',
                        render: (_, row) => {
                            const batch = payloadText(row, 'batch_number');
                            const shift = payloadText(row, 'shift');

                            return (
                                <Space direction="vertical" size={0}>
                                    {batch && <span>Batch {batch}</span>}
                                    {!batch && shift && <span>{shift} shift</span>}
                                    <Typography.Text type="secondary">
                                        {row.syncable_type} #{row.syncable_id}
                                    </Typography.Text>
                                    <Link to="/production/shift-production">Open production entries</Link>
                                </Space>
                            );
                        },
                    },
                    {
                        title: 'Status',
                        render: (_, row) =>
                            row.hold ? (
                                // A held shift voucher is deliberately not with
                                // the agent yet (DEC-20260807-011) — different
                                // claim from "waiting for agent", which reads
                                // as "the factory machine should have taken
                                // this already".
                                <Space direction="vertical" size={0}>
                                    <Tag color="blue">
                                        {row.hold.phase === 'collecting' ? 'Collecting the shift' : 'Quiet period'}
                                    </Tag>
                                    <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                                        {row.hold.phase === 'collecting'
                                            ? `Collecting until ${instant(row.hold.shift_ends_at)}`
                                            : `Waiting: quiet period — last entry joined ${instant(row.hold.last_merged_at)}`}
                                    </Typography.Text>
                                </Space>
                            ) : (
                                <Tag color={statusColor[row.status]}>{statusLabel[row.status]}</Tag>
                            ),
                    },
                    {
                        title: 'Tries',
                        dataIndex: 'attempts',
                        align: 'right',
                    },
                    {
                        title: 'What Tally said',
                        render: (_, row) => {
                            const outcome = outcomes[row.id];

                            return (
                                <div style={{ maxWidth: 380, whiteSpace: 'normal' }}>
                                    {row.error_message ? (
                                        <>
                                            <Typography.Text type="danger">{row.error_message}</Typography.Text>
                                            {row.fix && (
                                                <div style={{ marginTop: 4 }}>
                                                    <Typography.Text style={{ fontSize: 12 }}>
                                                        {row.fix.sentence}{' '}
                                                        <Link to={row.fix.path}>Open the fix</Link>
                                                    </Typography.Text>
                                                </div>
                                            )}
                                        </>
                                    ) : (
                                        <Typography.Text type="secondary">—</Typography.Text>
                                    )}
                                    {(row.resolution_log?.length ?? 0) > 0 && !row.error_message && (
                                        <Typography.Text type="success" style={{ display: 'block', fontSize: 12 }}>
                                            Fixed after {row.resolution_log!.length} failed attempt{row.resolution_log!.length > 1 ? 's' : ''} — payload regenerated from current mappings.
                                        </Typography.Text>
                                    )}
                                    {outcome && (
                                        <div style={{ marginTop: 4 }}>
                                            <Typography.Text type={outcome.ok ? 'success' : 'warning'}>
                                                {outcome.message}
                                            </Typography.Text>
                                        </div>
                                    )}
                                </div>
                            );
                        },
                    },
                    {
                        title: 'Last activity',
                        render: (_, row) => (
                            <Space direction="vertical" size={0}>
                                {row.synced_at && <span>In Tally {instant(row.synced_at)}</span>}
                                {!row.synced_at && row.delivered_at && (
                                    <Tooltip title="The agent has taken this voucher but has not reported back yet.">
                                        <span>Collected {instant(row.delivered_at)}</span>
                                    </Tooltip>
                                )}
                                {!row.synced_at && !row.delivered_at && (
                                    <Typography.Text type="secondary">Not collected yet</Typography.Text>
                                )}
                                <Typography.Text type="secondary">Queued {instant(row.created_at)}</Typography.Text>
                            </Space>
                        ),
                    },
                    {
                        title: 'Actions',
                        render: (_, row) => {
                            const view = (
                                <Button size="small" onClick={() => setViewing(row)}>
                                    View
                                </Button>
                            );

                            if (row.hold) {
                                return (
                                    <Space>
                                        {view}
                                        <Tooltip title="Send this shift's voucher to Tally without waiting for the shift end / quiet period. Press View first to see exactly what will post.">
                                            <Button
                                                size="small"
                                                loading={releasingId === row.id}
                                                disabled={busy && releasingId !== row.id}
                                                onClick={() => releaseOne(row)}
                                            >
                                                Release now
                                            </Button>
                                        </Tooltip>
                                    </Space>
                                );
                            }

                            return (
                                <Space>
                                    {view}
                                    {row.status === 'failed' && (
                                        <Button
                                            danger
                                            size="small"
                                            loading={retryingId === row.id}
                                            disabled={busy && retryingId !== row.id}
                                            onClick={() => resyncOne(row)}
                                        >
                                            Resync
                                        </Button>
                                    )}
                                </Space>
                            );
                        },
                    },
                ]}
                expandable={{
                    expandedRowRender: (row) => (
                        <>
                            <Typography.Text type="secondary">
                                Exactly what the agent was given to post:
                            </Typography.Text>
                            <pre style={{ margin: 0, whiteSpace: 'pre-wrap' }}>{JSON.stringify(row.payload, null, 2)}</pre>
                        </>
                    ),
                }}
            />

            <Modal
                open={viewing !== null}
                title={viewing ? `${voucherNumber(viewing)} — as it goes to Tally` : ''}
                onCancel={() => setViewing(null)}
                onOk={() => setViewing(null)}
                width={720}
                cancelButtonProps={{ style: { display: 'none' } }}
            >
                {viewing && (() => {
                    const consumed = voucherStockLines(viewing, 'consumed');
                    const produced = voucherStockLines(viewing, 'produced');
                    const lineColumns = [
                        { title: 'Item', dataIndex: 'item' },
                        {
                            title: 'Godown',
                            render: (_: unknown, line: VoucherStockLine) =>
                                line.godown ?? payloadText(viewing, 'godown') ?? '—',
                        },
                        { title: 'Quantity', dataIndex: 'quantity', align: 'right' as const },
                    ];

                    if (consumed === null || produced === null) {
                        // Non-production vouchers (sales, notes, journals)
                        // have no two-sided stock shape — show the payload.
                        return (
                            <pre style={{ margin: 0, whiteSpace: 'pre-wrap' }}>
                                {JSON.stringify(viewing.payload, null, 2)}
                            </pre>
                        );
                    }

                    return (
                        <Space direction="vertical" size={16} style={{ width: '100%' }}>
                            <Space direction="vertical" size={0}>
                                {/* What Tally RECEIVES: the production builder
                                    emits a plain Stock Journal whatever the
                                    dispatch label says — same layout as the
                                    accountant's own vouchers. */}
                                <span>Posts as a <strong>Stock Journal</strong> dated <strong>{payloadText(viewing, 'voucher_date') ?? '—'}</strong></span>
                                {payloadText(viewing, 'shift') && <span>Shift: {payloadText(viewing, 'shift')}</span>}
                                {payloadText(viewing, 'batch_number') && <span>Batch: {payloadText(viewing, 'batch_number')}</span>}
                                {payloadText(viewing, 'narration') && (
                                    <Typography.Text type="secondary">{payloadText(viewing, 'narration')}</Typography.Text>
                                )}
                            </Space>
                            <div>
                                <Typography.Text strong>Consumption (Source) — stock out</Typography.Text>
                                <Table<VoucherStockLine>
                                    size="small"
                                    rowKey={(line) => `c-${line.item}-${line.godown ?? ''}`}
                                    dataSource={consumed}
                                    pagination={false}
                                    columns={lineColumns}
                                />
                            </div>
                            <div>
                                <Typography.Text strong>Production (Destination) — stock in</Typography.Text>
                                <Table<VoucherStockLine>
                                    size="small"
                                    rowKey={(line) => `p-${line.item}-${line.godown ?? ''}`}
                                    dataSource={produced}
                                    pagination={false}
                                    columns={lineColumns}
                                />
                            </div>
                        </Space>
                    );
                })()}
            </Modal>

            <Modal
                open={report !== null}
                title="Resync results"
                onCancel={() => setReport(null)}
                onOk={() => setReport(null)}
                cancelButtonProps={{ style: { display: 'none' } }}
            >
                <Space direction="vertical" size={12} style={{ width: '100%' }}>
                    {(report ?? []).map((result) => (
                        <div key={result.id}>
                            <Tag color={result.ok ? 'green' : 'red'}>{result.voucher}</Tag>
                            <div>{result.message}</div>
                        </div>
                    ))}
                </Space>
            </Modal>
        </>
    );
}
