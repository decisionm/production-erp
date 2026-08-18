import { useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Alert, Button, Collapse, Descriptions, Drawer, Space, Table, Tag, Timeline, Tooltip, Typography } from 'antd';
import { Link } from 'react-router-dom';
import { getTallySyncEntry } from '@/features/tally-sync/api';
import {
    formatXml,
    holdCopy,
    instant,
    mappingBadge,
    mappingStateShort,
    payloadColumns,
    payloadText,
    snapshotAnswer,
    snapshotHeadline,
    snapshotXmlDecision,
    sourceLink,
    statusColor,
    statusLabel,
    timelineItems,
    voucherNumber,
    voucherStockLines,
    type MappingBadge,
    type VoucherStockLine,
    showsFixedAfterFailures,
} from '@/features/tally-sync/drawer';
import { categoryLabel } from '@/features/tally-sync/filters';
import type { EntryMappings, LedgerMapping, MappingSummary, TallySyncEntry, TallySyncSnapshot } from '@/features/tally-sync/types';

/**
 * The row's actions, lifted from the page: the SAME Release now / Resync /
 * Dismiss handlers the table row calls, so the drawer's footer and the row
 * can never disagree about what a click does. The page owns the mutations
 * and the busy state; the drawer only calls back.
 */
export interface EntryDrawerActions {
    busy: boolean;
    retryingId: number | null;
    releasingId: number | null;
    dismissingId: number | null;
    onResync: (entry: TallySyncEntry) => void;
    onRelease: (entry: TallySyncEntry) => void;
    onDismiss: (entry: TallySyncEntry) => void;
}

interface EntryDrawerProps extends EntryDrawerActions {
    /** The entry to show; null closes the drawer. */
    entryId: number | null;
    /**
     * The row as the LIST holds it — painted at once while the show endpoint
     * answers, and the fallback for the sections the list already carries
     * (source, voucher, release, result). Summary, timeline, mappings and
     * snapshots only ever come from the show endpoint.
     */
    listRow: TallySyncEntry | null;
    /** The page's per-row action outcome ("Queued again — …"), shown in the Result section. */
    outcome?: { ok: boolean; message: string } | null;
    onClose: () => void;
}

/** A mapping-state Tag with the backend's note as its tooltip (none when there is no note). */
function StateTag({ badge }: { badge: MappingBadge }) {
    const tag = <Tag color={badge.color} style={{ marginInlineEnd: 0 }}>{badge.text}</Tag>;

    return badge.title ? <Tooltip title={badge.title}>{tag}</Tooltip> : tag;
}

/** A name and how it resolved, on one line. */
function MappedName({ name, badge }: { name: string | null; badge: MappingBadge }) {
    return (
        <Space size={6} wrap>
            <span>{name ?? <Typography.Text type="secondary">—</Typography.Text>}</span>
            <StateTag badge={badge} />
        </Space>
    );
}

function LedgerRow({ ledger }: { ledger: LedgerMapping }) {
    return <MappedName name={ledger.name} badge={mappingBadge(ledger.state, ledger.note)} />;
}

/** One line of a section, in the page's typography. */
function SectionTitle({ children }: { children: string }) {
    return (
        <Typography.Title level={5} style={{ marginTop: 20, marginBottom: 8 }}>
            {children}
        </Typography.Title>
    );
}

/** A payload cell — strings as they are, nulls as a dash, anything else as JSON. */
function cell(value: unknown): string {
    if (value === null || value === undefined || value === '') return '—';
    if (typeof value === 'string') return value;
    if (typeof value === 'number' || typeof value === 'boolean') return String(value);

    return JSON.stringify(value);
}

/**
 * The detail drawer for one queued voucher — the chain in the order it
 * runs (TALLY-SYNC-CHAIN.md §1): ERP source → voucher → mappings → release
 * → payload → result → what the agent sent / what Tally answered →
 * timeline. Fed by GET /tally-sync/entries/{id} (summary, timeline,
 * mappings and snapshots ride only on that endpoint); the list's row
 * paints first. Nothing here changes what reaches Tally.
 */
export default function EntryDrawer({
    entryId,
    listRow,
    outcome,
    onClose,
    busy,
    retryingId,
    releasingId,
    dismissingId,
    onResync,
    onRelease,
    onDismiss,
}: EntryDrawerProps) {
    // Keyed under ['tally-sync', 'entries', …] so the page's invalidation
    // after Resync / Dismiss / Release refetches this too — the drawer
    // shows the new status without a second wiring.
    const { data: detail, isLoading, isError, refetch } = useQuery({
        queryKey: ['tally-sync', 'entries', 'show', entryId],
        queryFn: () => getTallySyncEntry(entryId as number),
        enabled: entryId !== null,
    });

    // The entry on screen. While the drawer slides shut entryId is already
    // null (nothing to show, query disabled), so the last entry shown is
    // kept for the closing frames — otherwise the content flashes to
    // "Loading…" on the way out. React's "adjust state during render"
    // pattern; destroyOnHidden throws it away once the drawer is closed.
    const current = detail ?? listRow;
    const [lastShown, setLastShown] = useState<TallySyncEntry | null>(null);
    if (current && current !== lastShown) {
        setLastShown(current);
    }
    // Only for the closing frames — a freshly opened id whose row is not
    // in the list shows "Loading…", never the previous voucher.
    const entry = current ?? (entryId === null ? lastShown : null);

    return (
        <Drawer
            open={entryId !== null}
            onClose={onClose}
            width="min(100vw, 760px)"
            destroyOnHidden
            title={entry ? `${voucherNumber(entry)} — as it goes to Tally` : 'Voucher'}
            footer={
                entry && (
                    <Space wrap>
                        <Button onClick={onClose}>Close</Button>
                        {entry.hold && (
                            <Tooltip title="Send this shift's voucher to Tally without waiting for the shift end / quiet period.">
                                <Button
                                    size="small"
                                    loading={releasingId === entry.id}
                                    disabled={busy && releasingId !== entry.id}
                                    onClick={() => onRelease(entry)}
                                >
                                    Release now
                                </Button>
                            </Tooltip>
                        )}
                        {!entry.hold && entry.status === 'failed' && (
                            <Button
                                danger
                                size="small"
                                loading={retryingId === entry.id}
                                disabled={busy && retryingId !== entry.id}
                                onClick={() => onResync(entry)}
                            >
                                Resync
                            </Button>
                        )}
                        {!entry.hold && entry.status === 'failed' && (
                            <Tooltip title="Write this voucher off — it will never be sent to Tally. For dead vouchers (demo data) that must not reach the books.">
                                <Button
                                    size="small"
                                    loading={dismissingId === entry.id}
                                    disabled={busy && dismissingId !== entry.id}
                                    onClick={() => onDismiss(entry)}
                                >
                                    Dismiss
                                </Button>
                            </Tooltip>
                        )}
                    </Space>
                )
            }
        >
            {isError && (
                <Alert
                    type="error"
                    showIcon
                    style={{ marginBottom: 12 }}
                    message="Could not load this voucher's detail"
                    description={
                        <Space direction="vertical" size={8}>
                            <span>
                                The summary, mappings and timeline below need the ERP to answer for this one voucher.
                                {listRow ? ' What the list already knew is shown.' : ''}
                            </span>
                            <Button size="small" onClick={() => refetch()}>
                                Try again
                            </Button>
                        </Space>
                    }
                />
            )}

            {!entry && !isError && <Typography.Text type="secondary">Loading…</Typography.Text>}

            {entry && (
                // Keyed by id so the raw-payload toggle starts closed for
                // every voucher rather than carrying over from the last one.
                <EntryBody key={entry.id} entry={entry} detailLoading={isLoading && !detail} outcome={outcome ?? null} />
            )}
        </Drawer>
    );
}

function EntryBody({
    entry,
    detailLoading,
    outcome,
}: {
    entry: TallySyncEntry;
    detailLoading: boolean;
    outcome: { ok: boolean; message: string } | null;
}) {
    const [showRaw, setShowRaw] = useState(false);
    const flags = entry.flags ?? {};
    const link = sourceLink(entry);
    const wireType = entry.category?.wire_voucher_type ?? entry.tally_voucher_type;
    const pendingDetail = detailLoading ? (
        <Typography.Text type="secondary">Loading from the ERP…</Typography.Text>
    ) : (
        <Typography.Text type="secondary">Not available for this voucher.</Typography.Text>
    );

    return (
        <>
            {/* The honesty banners — each one is a fact the row's own words
                would otherwise hide, in the backend's sentence, not ours. */}
            {flags.unvalidated_builder && (
                <Alert
                    type="warning"
                    showIcon
                    style={{ marginBottom: 12 }}
                    message={<strong>Unvalidated voucher builder</strong>}
                    description={flags.unvalidated_builder.note}
                />
            )}
            {flags.order_reference_not_emitted && (
                <Alert
                    type="info"
                    showIcon
                    style={{ marginBottom: 12 }}
                    message={<strong>Order reference does not reach Tally</strong>}
                    description={flags.order_reference_not_emitted.note}
                />
            )}
            {entry.category?.source === 'unknown' && (
                <Alert
                    type="warning"
                    showIcon
                    style={{ marginBottom: 12 }}
                    message={<strong>Unclassified voucher type</strong>}
                    description={`The ERP could not place '${entry.tally_voucher_type}' in any transaction category. What is shown below is read from the payload as it stands; nothing about it is assumed.`}
                />
            )}

            {/* 1 · ERP source */}
            <SectionTitle>ERP source</SectionTitle>
            <Descriptions
                column={1}
                size="small"
                bordered
                items={[
                    { key: 'module', label: 'Module', children: entry.category?.source_module ?? '—' },
                    { key: 'document', label: 'Document', children: entry.document_number ?? voucherNumber(entry) },
                    { key: 'date', label: 'Business date', children: entry.business_date ?? '—' },
                    {
                        key: 'party',
                        label: 'Party',
                        // FC-06: on a Receipt Note the party is the vendor, and
                        // for a reader who may not see who supplied it the show
                        // endpoint says so (mappings.party.state = 'withheld')
                        // — rendered as such, not as a blank that would read
                        // "no party".
                        children: entry.mappings?.party?.state === 'withheld' ? (
                            <Tooltip title={entry.mappings.party.note ?? undefined}>
                                <Typography.Text type="secondary">withheld (FC-06)</Typography.Text>
                            </Tooltip>
                        ) : (
                            entry.party ?? <Typography.Text type="secondary">—</Typography.Text>
                        ),
                    },
                    {
                        key: 'syncable',
                        label: 'Source record',
                        children: (
                            <Space direction="vertical" size={0}>
                                <span>
                                    {entry.syncable_type} #{entry.syncable_id}
                                </span>
                                {payloadText(entry, 'batch_number') && <span>Batch {payloadText(entry, 'batch_number')}</span>}
                                {!payloadText(entry, 'batch_number') && payloadText(entry, 'shift') && (
                                    <span>{payloadText(entry, 'shift')} shift</span>
                                )}
                                {link && <Link to={link.to}>{link.label}</Link>}
                            </Space>
                        ),
                    },
                    {
                        key: 'category',
                        label: 'Category',
                        children: (
                            <Space direction="vertical" size={0}>
                                {/* categoryLabel() adds "· posts as Stock Journal"
                                    where the ERP's label is not what Tally receives. */}
                                <span>{categoryLabel(entry.category)}</span>
                                {flags.label_differs_from_wire && (
                                    <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                                        {flags.label_differs_from_wire.note}
                                    </Typography.Text>
                                )}
                            </Space>
                        ),
                    },
                ]}
            />

            {/* 2 · Voucher */}
            <SectionTitle>Voucher</SectionTitle>
            <Descriptions
                column={1}
                size="small"
                bordered
                items={[
                    { key: 'wire', label: 'Posts to Tally as', children: <strong>{wireType}</strong> },
                    ...(entry.tally_voucher_type !== wireType
                        ? [{ key: 'label', label: 'ERP label', children: entry.tally_voucher_type }]
                        : []),
                    { key: 'number', label: 'Voucher number', children: voucherNumber(entry) },
                    { key: 'vdate', label: 'Voucher date', children: payloadText(entry, 'voucher_date') ?? '—' },
                    ...(payloadText(entry, 'narration')
                        ? [{ key: 'narration', label: 'Narration', children: payloadText(entry, 'narration') }]
                        : []),
                    {
                        key: 'summary',
                        label: 'In one line',
                        children: entry.summary ? (
                            <Space direction="vertical" size={2}>
                                <span>{entry.summary.headline}</span>
                                {entry.summary.lines.length > 0 && (
                                    <ul style={{ margin: 0, paddingInlineStart: 18 }}>
                                        {entry.summary.lines.map((line, index) => (
                                            <li key={`${index}-${line}`}>{line}</li>
                                        ))}
                                    </ul>
                                )}
                            </Space>
                        ) : (
                            pendingDetail
                        ),
                    },
                ]}
            />

            {/* 3 · Mappings */}
            <SectionTitle>Mappings</SectionTitle>
            {entry.mappings ? (
                <MappingsSection mappings={entry.mappings} summary={entry.mapping_summary} />
            ) : (
                pendingDetail
            )}

            {/* 4 · Release */}
            <SectionTitle>Release</SectionTitle>
            {entry.hold ? (
                <Space direction="vertical" size={0}>
                    <Tag color="blue">{holdCopy(entry.hold).tag}</Tag>
                    <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                        {holdCopy(entry.hold).detail}
                    </Typography.Text>
                    <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                        Releasable at {instant(entry.hold.releasable_at)} (an estimate: a later merge pushes it out).
                    </Typography.Text>
                </Space>
            ) : entry.released_at ? (
                <Typography.Text>Released ahead of the gate by a person, {instant(entry.released_at)}.</Typography.Text>
            ) : (
                <Typography.Text type="secondary">Not held by the release gate.</Typography.Text>
            )}

            {/* 5 · Payload */}
            <SectionTitle>Payload</SectionTitle>
            <PayloadSection entry={entry} />
            <div style={{ marginTop: 8 }}>
                <Button size="small" onClick={() => setShowRaw((value) => !value)}>
                    {showRaw ? 'Hide raw payload' : 'Show raw payload'}
                </Button>
                {showRaw && (
                    <>
                        <Typography.Text type="secondary" style={{ display: 'block', marginTop: 8 }}>
                            Exactly what the agent was given to post:
                        </Typography.Text>
                        <pre style={{ margin: 0, whiteSpace: 'pre-wrap' }}>{JSON.stringify(entry.payload, null, 2)}</pre>
                    </>
                )}
            </div>

            {/* 6 · Result */}
            <SectionTitle>Result</SectionTitle>
            <Descriptions
                column={1}
                size="small"
                bordered
                items={[
                    {
                        key: 'status',
                        label: 'Status',
                        children: <Tag color={statusColor[entry.status]}>{statusLabel[entry.status]}</Tag>,
                    },
                    { key: 'tries', label: 'Tries', children: entry.attempts },
                    {
                        key: 'collected',
                        label: 'Collected by the agent',
                        children: entry.delivered_at ? instant(entry.delivered_at) : <Typography.Text type="secondary">Not collected yet</Typography.Text>,
                    },
                    {
                        key: 'synced',
                        label: 'In Tally',
                        children: entry.synced_at ? instant(entry.synced_at) : <Typography.Text type="secondary">—</Typography.Text>,
                    },
                    {
                        key: 'said',
                        label: 'What Tally said',
                        children: (
                            <div style={{ whiteSpace: 'normal' }}>
                                {entry.error_message ? (
                                    <>
                                        <Typography.Text type="danger">{entry.error_message}</Typography.Text>
                                        {entry.fix && (
                                            <div style={{ marginTop: 4 }}>
                                                <Typography.Text style={{ fontSize: 12 }}>
                                                    {entry.fix.sentence}{' '}
                                                    <Link to={entry.fix.path}>Open the fix</Link>
                                                </Typography.Text>
                                            </div>
                                        )}
                                    </>
                                ) : entry.error_withheld ? (
                                    <Typography.Text type="warning">{entry.error_withheld}</Typography.Text>
                                ) : (
                                    <Typography.Text type="secondary">—</Typography.Text>
                                )}
                                {showsFixedAfterFailures(entry) && (
                                    <Typography.Text type="success" style={{ display: 'block', fontSize: 12 }}>
                                        Fixed after {entry.resolution_log!.length} failed attempt{entry.resolution_log!.length > 1 ? 's' : ''} — payload regenerated from current mappings.
                                    </Typography.Text>
                                )}
                                {outcome && (
                                    <div style={{ marginTop: 4 }}>
                                        <Typography.Text type={outcome.ok ? 'success' : 'warning'}>{outcome.message}</Typography.Text>
                                    </div>
                                )}
                            </div>
                        ),
                    },
                    ...((entry.resolution_log?.length ?? 0) > 0
                        ? [{
                            key: 'log',
                            label: 'Resolution log',
                            children: (
                                <ul style={{ margin: 0, paddingInlineStart: 18 }}>
                                    {entry.resolution_log!.map((row, index) => (
                                        <li key={`${row.at}-${index}`}>
                                            <Typography.Text type="secondary">{instant(row.at)}</Typography.Text>{' — '}
                                            {row.note}
                                            {row.previous_error && (
                                                <Typography.Text type="secondary"> (after: {row.previous_error})</Typography.Text>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            ),
                        }]
                        : []),
                ]}
            />

            {/* 7 · What the agent sent / What Tally answered (Phase 4) */}
            <SectionTitle>What the agent sent / What Tally answered</SectionTitle>
            {entry.snapshots ? <SnapshotsSection snapshots={entry.snapshots} /> : pendingDetail}

            {/* 8 · Timeline */}
            <SectionTitle>Timeline</SectionTitle>
            {entry.timeline ? (
                entry.timeline.length === 0 ? (
                    <Typography.Text type="secondary">Nothing recorded for this voucher yet.</Typography.Text>
                ) : (
                    <Timeline
                        items={timelineItems(entry.timeline).map((row) => ({
                            key: row.key,
                            color: row.color,
                            content: (
                                <div style={{ opacity: row.muted ? 0.65 : 1 }}>
                                    <Space size={6} wrap>
                                        <strong>{row.label}</strong>
                                        {row.tag && (
                                            <Tooltip title="Reconstructed from the entry's own timestamps, not observed as it happened — who did it is not known.">
                                                <Tag style={{ marginInlineEnd: 0 }}>{row.tag}</Tag>
                                            </Tooltip>
                                        )}
                                    </Space>
                                    <div>
                                        <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                                            {instant(row.at)}
                                            {row.actor ? ` · ${row.actor}` : ''}
                                        </Typography.Text>
                                    </div>
                                    {row.detail && <div style={{ whiteSpace: 'normal' }}>{row.detail}</div>}
                                </div>
                            ),
                        }))}
                    />
                )
            ) : (
                pendingDetail
            )}
        </>
    );
}

function MappingsSection({ mappings, summary }: { mappings: EntryMappings; summary: MappingSummary | undefined }) {
    const showsSide = mappings.lines.some((line) => line.side !== 'lines');
    const nothingToMap = mappings.lines.length === 0 && mappings.ledgers.length === 0 && !mappings.party && !mappings.sales_ledger;

    return (
        <Space direction="vertical" size={12} style={{ width: '100%' }}>
            {summary && (
                <Space size={6} wrap>
                    {(Object.keys(mappingStateShort) as (keyof MappingSummary)[]).map((state) => {
                        const count = summary[state] ?? 0;

                        return (
                            <Tag key={state} color={count > 0 ? mappingBadge(state).color : 'default'} style={{ marginInlineEnd: 0 }}>
                                {mappingStateShort[state]}: {count}
                            </Tag>
                        );
                    })}
                </Space>
            )}

            {nothingToMap && <Typography.Text type="secondary">This voucher hands Tally no name to resolve.</Typography.Text>}

            {mappings.lines.length > 0 && (
                <Table<EntryMappings['lines'][number]>
                    size="small"
                    pagination={false}
                    scroll={{ x: 'max-content' }}
                    rowKey="key"
                    dataSource={mappings.lines.map((line, index) => ({ ...line, key: index }))}
                    columns={[
                        ...(showsSide
                            ? [{ title: 'Side', render: (_: unknown, line: EntryMappings['lines'][number]) => line.side }]
                            : []),
                        {
                            title: 'Item',
                            render: (_, line) => (
                                <MappedName
                                    name={line.item.name}
                                    badge={{
                                        ...mappingBadge(line.item.state, line.item.note, line.item.shared_count),
                                        // An identity with no note still has a
                                        // fact worth a hover: the GUID Tally will match.
                                        title: mappingBadge(line.item.state, line.item.note, line.item.shared_count).title
                                            ?? (line.item.tally_stock_item_guid ? `Tally GUID ${line.item.tally_stock_item_guid}` : null),
                                    }}
                                />
                            ),
                        },
                        {
                            title: 'Godown',
                            render: (_, line) => (
                                <MappedName
                                    name={line.godown.name}
                                    badge={{
                                        ...mappingBadge(line.godown.state, line.godown.note, line.godown.shared_count),
                                        title: mappingBadge(line.godown.state, line.godown.note, line.godown.shared_count).title
                                            ?? (line.godown.tally_guid ? `Tally GUID ${line.godown.tally_guid}` : null),
                                    }}
                                />
                            ),
                        },
                    ]}
                />
            )}

            {mappings.ledgers.length > 0 && (
                <Table<LedgerMapping>
                    size="small"
                    pagination={false}
                    scroll={{ x: 'max-content' }}
                    rowKey="key"
                    dataSource={mappings.ledgers.map((ledger, index) => ({ ...ledger, key: index }))}
                    columns={[{ title: 'Ledger', render: (_, ledger) => <LedgerRow ledger={ledger} /> }]}
                />
            )}

            {(mappings.party || mappings.sales_ledger) && (
                <Descriptions
                    column={1}
                    size="small"
                    bordered
                    items={[
                        ...(mappings.party
                            ? [{ key: 'party', label: 'Party ledger', children: <LedgerRow ledger={mappings.party} /> }]
                            : []),
                        ...(mappings.sales_ledger
                            ? [{ key: 'sales', label: 'Sales ledger', children: <LedgerRow ledger={mappings.sales_ledger} /> }]
                            : []),
                    ]}
                />
            )}
        </Space>
    );
}

/**
 * The agent's own record of each post (Phase 4): the XML it built locally
 * and Tally's answer, one collapsible panel per attempt, newest first with
 * the newest open. Everything shown is what the SERVER sent — the XML
 * arrives only for a reader with finance standing (or for a Stock Journal,
 * which carries no rate and no party), and the withheld note is rendered
 * in its place in the same warning style the Result section uses for a
 * withheld rejection (FC-06). Nothing here reads Tally or the agent.
 */
function SnapshotsSection({ snapshots }: { snapshots: TallySyncSnapshot[] }) {
    if (snapshots.length === 0) {
        return (
            <Typography.Text type="secondary">
                No snapshot yet — the agent uploads one after each post (agent ≥ 0.3.8).
            </Typography.Text>
        );
    }

    return (
        <Collapse
            size="small"
            defaultActiveKey={[String(snapshots[0].id)]}
            items={snapshots.map((snapshot) => {
                const answer = snapshotAnswer(snapshot);
                const verdict = answer.kind === 'answer' ? answer.tags[0] : { color: 'default' as const, text: 'no answer' };

                return {
                    key: String(snapshot.id),
                    label: (
                        <Tooltip title={`sha256 ${snapshot.xml_sha256}`}>
                            <Typography.Text style={{ fontSize: 12 }}>{snapshotHeadline(snapshot)}</Typography.Text>
                        </Tooltip>
                    ),
                    extra: <Tag color={verdict.color} style={{ marginInlineEnd: 0 }}>{verdict.text}</Tag>,
                    children: <SnapshotPanel snapshot={snapshot} />,
                };
            })}
        />
    );
}

function SnapshotPanel({ snapshot }: { snapshot: TallySyncSnapshot }) {
    const xml = snapshotXmlDecision(snapshot);
    const answer = snapshotAnswer(snapshot);
    // Formatting is for eyes; a 2 MB voucher is formatted once, not per render.
    const pretty = useMemo(() => (xml.kind === 'xml' ? formatXml(xml.text) : ''), [xml.kind, xml.text]);
    const [copied, setCopied] = useState<'idle' | 'done' | 'failed'>('idle');
    const [showRaw, setShowRaw] = useState(false);
    const rejected = answer.kind === 'answer' && answer.tags[0]?.text === 'rejected';

    // The RAW string is copied — byte for byte what the agent posted, so a
    // sha256 over the clipboard matches the header. navigator.clipboard is
    // absent on an insecure origin (a LAN box over http) and refuses
    // without a user gesture; either way the button says so, nothing throws.
    const copy = async () => {
        try {
            await navigator.clipboard.writeText(xml.text);
            setCopied('done');
        } catch {
            setCopied('failed');
        }
        window.setTimeout(() => setCopied('idle'), 2000);
    };

    return (
        <Space direction="vertical" size={12} style={{ width: '100%' }}>
            <div>
                <Space size={8} wrap style={{ marginBottom: 6 }}>
                    <Typography.Text strong>What the agent sent</Typography.Text>
                    {xml.kind === 'xml' && (
                        <Button size="small" onClick={copy}>
                            {copied === 'done' ? 'Copied' : copied === 'failed' ? 'Copy failed — select the text instead' : 'Copy XML'}
                        </Button>
                    )}
                </Space>
                {xml.kind === 'xml' && (
                    <pre style={{ margin: 0, maxHeight: 360, overflow: 'auto', fontSize: 12, whiteSpace: 'pre' }}>{pretty}</pre>
                )}
                {xml.kind === 'withheld' && (
                    <Typography.Text type="warning" style={{ display: 'block' }}>
                        {xml.text}
                    </Typography.Text>
                )}
                {xml.kind === 'none' && (
                    <Typography.Text type="secondary" style={{ display: 'block' }}>
                        {xml.text}
                    </Typography.Text>
                )}
            </div>

            <div>
                <Typography.Text strong>What Tally answered</Typography.Text>
                {answer.kind === 'none' ? (
                    <div style={{ marginTop: 6 }}>
                        <Typography.Text type="secondary">
                            No answer recorded — the XML was sent and Tally did not answer before the agent gave up (inconclusive).
                        </Typography.Text>
                    </div>
                ) : (
                    <>
                        <div style={{ marginTop: 6 }}>
                            <Space size={6} wrap>
                                {answer.tags.map((tag) => (
                                    <Tag key={tag.text} color={tag.color} style={{ marginInlineEnd: 0 }}>
                                        {tag.text}
                                    </Tag>
                                ))}
                            </Space>
                        </div>
                        {answer.message && (
                            <div style={{ marginTop: 4, whiteSpace: 'normal' }}>
                                <Typography.Text type={rejected ? 'danger' : undefined}>{answer.message}</Typography.Text>
                            </div>
                        )}
                        {answer.messageWithheld && (
                            <div style={{ marginTop: 4, whiteSpace: 'normal' }}>
                                <Typography.Text type="warning">{answer.messageWithheld}</Typography.Text>
                            </div>
                        )}
                        {answer.raw && (
                            <div style={{ marginTop: 8 }}>
                                <Button size="small" onClick={() => setShowRaw((value) => !value)}>
                                    {showRaw ? 'Hide raw response' : 'Show raw response'}
                                </Button>
                                {showRaw && (
                                    <pre style={{ margin: '8px 0 0', maxHeight: 240, overflow: 'auto', fontSize: 12, whiteSpace: 'pre-wrap' }}>
                                        {answer.raw}
                                    </pre>
                                )}
                            </div>
                        )}
                    </>
                )}
            </div>
        </Space>
    );
}

/**
 * The payload as a table per type: production keeps its two-sided
 * consumed/produced view; Sales, Receipt Note, Delivery Note and Journal
 * render their lines[] with the columns the server actually sent
 * (payloadColumns — Rate / Amount only when present).
 */
function PayloadSection({ entry }: { entry: TallySyncEntry }) {
    const consumed = voucherStockLines(entry, 'consumed');
    const produced = voucherStockLines(entry, 'produced');

    if (consumed !== null && produced !== null) {
        const lineColumns = [
            { title: 'Item', dataIndex: 'item' },
            {
                title: 'Godown',
                render: (_: unknown, line: VoucherStockLine) => line.godown ?? payloadText(entry, 'godown') ?? '—',
            },
            { title: 'Quantity', dataIndex: 'quantity', align: 'right' as const },
        ];

        return (
            <Space direction="vertical" size={16} style={{ width: '100%' }}>
                <Space direction="vertical" size={0}>
                    {/* What Tally RECEIVES: the production builder emits a
                        plain Stock Journal whatever the dispatch label says. */}
                    <span>Posts as a <strong>Stock Journal</strong> dated <strong>{payloadText(entry, 'voucher_date') ?? '—'}</strong></span>
                    {payloadText(entry, 'shift') && <span>Shift: {payloadText(entry, 'shift')}</span>}
                    {payloadText(entry, 'batch_number') && <span>Batch: {payloadText(entry, 'batch_number')}</span>}
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
    }

    const raw = entry.payload?.lines;
    const lines = Array.isArray(raw)
        ? raw.filter((line): line is Record<string, unknown> => typeof line === 'object' && line !== null && !Array.isArray(line))
        : [];

    if (lines.length === 0) {
        return <Typography.Text type="secondary">No lines on this payload — see the raw payload below.</Typography.Text>;
    }

    const columns = payloadColumns(entry.category?.key ?? 'unknown', lines[0]);
    const godown = payloadText(entry, 'godown');

    return (
        <Space direction="vertical" size={8} style={{ width: '100%' }}>
            {godown && <span>Godown: <strong>{godown}</strong></span>}
            {payloadText(entry, 'party_ledger') && <span>Party ledger: <strong>{payloadText(entry, 'party_ledger')}</strong></span>}
            {payloadText(entry, 'sales_ledger') && <span>Sales ledger: <strong>{payloadText(entry, 'sales_ledger')}</strong></span>}
            <Table<Record<string, unknown>>
                size="small"
                pagination={false}
                scroll={{ x: 'max-content' }}
                rowKey="__key"
                dataSource={lines.map((line, index) => ({ ...line, __key: index }))}
                columns={columns.map((column) => ({
                    title: column.title,
                    align: column.align,
                    render: (_: unknown, line: Record<string, unknown>) => cell(line[column.key]),
                }))}
            />
        </Space>
    );
}
