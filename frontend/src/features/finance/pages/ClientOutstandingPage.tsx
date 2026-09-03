import { UploadOutlined } from '@ant-design/icons';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Button, Card, Col, Empty, Input, Row, Segmented, Space, Statistic, Table, Tag, Tooltip, Typography, Upload } from 'antd';
import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { useAuthStore } from '@/features/auth/store';
import { getClientOutstanding, importClientOutstanding } from '@/features/finance/api';
import {
    OUTSTANDING_IMPORT_ACCEPT,
    type OutstandingImportOutcome,
    canImportClientOutstanding,
    outstandingImportOutcome,
} from '@/features/finance/outstandingImport';
import type { AgeingBucket, ClientOutstanding, OutstandingBill, PendingOrderLine } from '@/features/finance/types';
import { columnSorter, filterOptions, onFilterBy } from '@/lib/clientSort';
import { showApiError } from '@/lib/showApiError';
import { TABLE_STICKY } from '@/lib/tableProps';

/** The one query key the position lives under — read here, invalidated after an import. */
const CLIENT_OUTSTANDING_KEY = ['finance', 'client-outstanding'] as const;

const { Text } = Typography;

/** The name the Client column prints first: the ERP customer where linked, else the Tally ledger. */
function clientLabel(row: ClientOutstanding): string {
    return row.customer_id !== null ? (row.customer_name ?? row.party_ledger_name) : row.party_ledger_name;
}

/** Linked to an ERP customer or not — the second line of the Client column, as a filter. */
function clientLink(row: ClientOutstanding): 'linked' | 'unlinked' {
    return row.customer_id !== null ? 'linked' : 'unlinked';
}

const CLIENT_LINK_LABELS: Record<string, string> = { linked: 'Linked', unlinked: 'Not linked' };

/**
 * WHAT EVERY CLIENT OWES, HOW LONG THEY HAVE OWED IT, AND WHAT IS STILL TO
 * SHIP THEM — one screen, from the position the agent mirrored out of Tally.
 *
 * EVERY NUMBER ON THIS PAGE IS TALLY'S. Not one of them is computed from the
 * ERP's own `invoices` or `sales_orders`, which on this instance hold a
 * handful of rows: the factory raises its sales in Tally, and a page that
 * blended the two would present a fraction of the real position as the whole
 * of it. `AccountsReceivableService` still answers the ERP-books question
 * separately, and is a different report on purpose.
 *
 * IT IS A FINANCE PAGE, GATED BY `module:finance`. The rows name a client and
 * the money they owe — the factory's debtor book — which is the same class of
 * data the ERP-books receivables report is gated for. The CRM gate is held by
 * people who work leads and is the weaker of the two (owner decision,
 * 31-Aug-2026).
 *
 * THE PAGE IS ONLY EVER AS CURRENT AS THE LAST PULL, and says so in a banner
 * rather than implying live figures. The agent reads Tally only when an
 * operator presses the tray button — the factory's rule since the Aug-2026
 * corruption scare — so "as at" is a real and sometimes old date.
 */

/** Money arrives as a decimal STRING and is only ever formatted, never parsed to a number for display. */
function money(value: string | null): string {
    if (value === null) return '—';

    const n = Number(value);
    if (!Number.isFinite(n)) return value;

    return n.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

/** Sorting needs a number; display never uses this. */
function num(value: string | null): number {
    const n = Number(value ?? '0');
    return Number.isFinite(n) ? n : 0;
}

const BUCKET_LABELS: Record<AgeingBucket, string> = {
    current: 'Not yet due',
    d1_30: '1–30',
    d31_60: '31–60',
    d61_90: '61–90',
    d90_plus: '90+',
    no_due_date: 'No due date',
};

/**
 * How overdue is "bad". Colour is a SECOND channel here, never the only one —
 * the day count is always printed beside it.
 */
function overdueColor(days: number | null): string {
    if (days === null || days <= 0) return 'default';
    if (days <= 30) return 'gold';
    if (days <= 60) return 'orange';
    if (days <= 90) return 'volcano';
    return 'red';
}

type Focus = 'all' | 'overdue' | 'pending';


/**
 * Is this read one the reader must be WARNED about, rather than shown?
 *
 * Exported so the rule can be tested against the state TanStack actually
 * produced here, which is the whole reason this exists — see the call site.
 */
export function isStalledRead(q: { isSuccess: boolean; isError: boolean; fetchStatus: string }): boolean {
    return !q.isSuccess && (q.isError || q.fetchStatus === 'paused');
}

export default function ClientOutstandingPage() {
    const { data, isPending, isSuccess, isError, fetchStatus, failureReason, error } = useQuery({
        queryKey: CLIENT_OUTSTANDING_KEY,
        queryFn: getClientOutstanding,
    });

    const [search, setSearch] = useState('');
    const [focus, setFocus] = useState<Focus>('all');

    /*
     * THE HAND PATH, for the days the agent cannot deliver a position.
     *
     * The tray button on the factory PC stays the normal road. This is here
     * because the page can be — and on this instance has been — empty while
     * the agent is down, and the owner can still export Group Outstandings ›
     * Sundry Debtors › Pending Bills out of Tally by hand.
     */
    const queryClient = useQueryClient();
    const user = useAuthStore((state) => state.user);
    const mayImport = canImportClientOutstanding(user);
    const [importOutcome, setImportOutcome] = useState<OutstandingImportOutcome | null>(null);

    const importPosition = useMutation({
        // Wrapped, not passed by reference: useMutation calls mutationFn with a
        // second argument (its context), which would land in this function's
        // optional `asOf` and file the position under an object.
        mutationFn: (file: File) => importClientOutstanding(file),
        onSuccess: async (result) => {
            setImportOutcome(outstandingImportOutcome(result));

            /*
             * ALWAYS, INCLUDING AFTER A SKIP. The table has to come from the
             * server rather than from what the page happened to be holding:
             * `skipped_empty` says the POSITION was kept, which is not a
             * promise that nothing else about the read moved, and a refetch
             * is cheap next to a debtor figure that is quietly one upload
             * behind.
             */
            await queryClient.invalidateQueries({ queryKey: CLIENT_OUTSTANDING_KEY });
        },
        onError: (failure) => {
            // No stale outcome left standing over a refusal.
            setImportOutcome(null);
            showApiError(failure, 'Could not import the Tally export');
        },
    });

    const clients = useMemo(() => {
        const rows = data?.clients ?? [];
        const needle = search.trim().toLowerCase();

        return rows.filter((c) => {
            if (focus === 'overdue' && num(c.overdue_amount) <= 0) return false;
            if (focus === 'pending' && c.pending_order_count === 0) return false;

            if (needle === '') return true;

            return (
                c.party_ledger_name.toLowerCase().includes(needle) ||
                (c.customer_name ?? '').toLowerCase().includes(needle) ||
                (c.customer_code ?? '').toLowerCase().includes(needle)
            );
        });
    }, [data, search, focus]);

    /*
     * A READ THAT IS NOT COMING BACK IS SAID OUT LOUD.
     *
     * MEASURED, not assumed. Pointed at a backend without the route, this
     * query settled at `status: "pending", fetchStatus: "paused",
     * failureCount: 1, error: null` — TanStack's `networkMode: "online"`
     * PAUSES a retry rather than failing it, so `isError` never becomes true
     * and `status` never leaves "pending". A page that waits for `isError`
     * waits for ever.
     *
     * That is why this is not written as `if (isError)`. It was, and it never
     * fired: the screen showed the calm "nothing has been pulled from Tally
     * yet" banner over a 404, telling the reader to go and press a button on
     * the factory PC. A paused read and an empty position are opposite facts
     * and must never render the same way.
     *
     * `paused` is reported separately from a hard failure because it is a
     * different thing to act on: the request has not failed, it is not being
     * sent. Both are "you are not looking at the position".
     */
    const stalled = isStalledRead({ isSuccess, isError, fetchStatus });

    if (stalled) {
        const reason = (error as Error | null) ?? (failureReason as Error | null);

        return (
            <Alert
                type="error"
                showIcon
                title="Could not load the outstanding position"
                description={
                    fetchStatus === 'paused'
                        ? `The request is paused and is not being retried, so this page is not showing the factory's position — do not read it as "nothing is owed". ${reason?.message ?? 'Check the connection and reload.'}`
                        : (reason?.message ?? 'The server did not return the outstanding position. It has not been reported as empty — it has not been read at all.')
                }
            />
        );
    }

    const totals = data?.totals;
    const hasBalanceOnly = (data?.clients ?? []).some((client) => client.balance_only);
    const allBalancesOnly = (data?.clients.length ?? 0) > 0 && (data?.clients ?? []).every((client) => client.balance_only);

    /*
     * ONLY A SUCCESSFUL READ MAY SAY "nothing has been pulled".
     *
     * This was `!isLoading && data?.as_of == null`, which is absence of data —
     * and a failed request has no data either. Opening the page against a
     * backend missing the route showed the calm yellow banner telling the
     * reader to go and press a button on the factory PC, when what had
     * actually happened was a 404. That is the worst kind of wrong: it reads
     * as a normal, expected state, so nobody goes looking for a fault, and the
     * operator is sent to press a tray button that will not help.
     *
     * `isSuccess` means the server answered and the answer was an empty
     * position. Every other state — loading, retrying, errored — is not that,
     * and must not borrow this message.
     */
    const nothingPulled = isSuccess && (data?.as_of ?? null) === null;

    const columns = [
        {
            title: 'Client',
            key: 'client',
            fixed: 'left' as const,
            width: 260,
            sorter: columnSorter<ClientOutstanding>(clientLabel, 'text'),
            filters: filterOptions(data?.clients ?? [], clientLink, (value) => CLIENT_LINK_LABELS[String(value)] ?? String(value)),
            onFilter: onFilterBy<ClientOutstanding>(clientLink),
            render: (_: unknown, row: ClientOutstanding) => (
                <Space direction="vertical" size={0}>
                    {/* The ERP customer where the ledger has been linked; the
                        Tally ledger's own name where nobody has linked it yet.
                        An unlinked client owing money must never drop off. */}
                    {row.customer_id !== null ? (
                        <Link to={`/sales/customers?id=${row.customer_id}`}>{row.customer_name}</Link>
                    ) : (
                        <Text>{row.party_ledger_name}</Text>
                    )}
                    <Text type="secondary" style={{ fontSize: 12 }}>
                        {row.customer_id !== null ? row.party_ledger_name : 'Not linked to an ERP customer'}
                    </Text>
                </Space>
            ),
        },
        {
            title: 'Pending purchases',
            key: 'pending',
            align: 'right' as const,
            width: 160,
            sorter: (a: ClientOutstanding, b: ClientOutstanding) => num(a.pending_order_amount) - num(b.pending_order_amount),
            render: (_: unknown, row: ClientOutstanding) => (
                <Space direction="vertical" size={0} style={{ width: '100%' }}>
                    <Text>{money(row.pending_order_amount)}</Text>
                    <Text type="secondary" style={{ fontSize: 12 }}>
                        {row.pending_order_count} order{row.pending_order_count === 1 ? '' : 's'}
                        {/* Tally priced no value for these. Counted, never invented. */}
                        {row.pending_orders_without_value > 0 && (
                            <Tooltip title={`${row.pending_orders_without_value} pending line(s) carry no value in Tally, so they are counted but not included in the amount.`}>
                                {' '}<Tag color="default" style={{ marginInlineEnd: 0 }}>+{row.pending_orders_without_value} unpriced</Tag>
                            </Tooltip>
                        )}
                    </Text>
                </Space>
            ),
        },
        {
            title: 'Outstanding',
            key: 'outstanding',
            align: 'right' as const,
            width: 150,
            defaultSortOrder: 'descend' as const,
            sorter: (a: ClientOutstanding, b: ClientOutstanding) => num(a.outstanding_amount) - num(b.outstanding_amount),
            render: (_: unknown, row: ClientOutstanding) => {
                const inCredit = num(row.outstanding_amount) < 0;

                return (
                    <Space direction="vertical" size={0} style={{ width: '100%' }}>
                        <Text strong type={inCredit ? 'success' : undefined}>{money(row.outstanding_amount)}</Text>
                        {/* A negative balance is a real and useful state, not a
                            rendering accident — it is named rather than hidden. */}
                        {inCredit && <Text type="secondary" style={{ fontSize: 12 }}>in credit</Text>}
                    </Space>
                );
            },
        },
        {
            title: 'Overdue',
            key: 'overdue',
            align: 'right' as const,
            width: 140,
            sorter: (a: ClientOutstanding, b: ClientOutstanding) => num(a.overdue_amount) - num(b.overdue_amount),
            render: (_: unknown, row: ClientOutstanding) => money(row.overdue_amount),
        },
        {
            title: <Tooltip title="Days past the DUE date of this client's oldest overdue bill. Tally's own ageing screen counts days since the bill date instead, so the two will not match column for column.">Outstanding days</Tooltip>,
            key: 'days',
            align: 'right' as const,
            width: 150,
            sorter: (a: ClientOutstanding, b: ClientOutstanding) => (a.oldest_overdue_days ?? -1) - (b.oldest_overdue_days ?? -1),
            render: (_: unknown, row: ClientOutstanding) =>
                row.oldest_overdue_days === null ? (
                    <Text type="secondary">—</Text>
                ) : (
                    <Tag color={overdueColor(row.oldest_overdue_days)}>{row.oldest_overdue_days} days</Tag>
                ),
        },
        ...(Object.keys(BUCKET_LABELS) as AgeingBucket[]).map((bucket) => ({
            title: bucket === 'no_due_date' && hasBalanceOnly ? 'No bill detail' : BUCKET_LABELS[bucket],
            key: bucket,
            align: 'right' as const,
            width: 120,
            render: (_: unknown, row: ClientOutstanding) => {
                const amount = row.ageing[bucket];
                return num(amount) === 0 ? <Text type="secondary">—</Text> : money(amount);
            },
        })),
        {
            title: 'Bills',
            dataIndex: 'bill_count',
            key: 'bill_count',
            align: 'right' as const,
            width: 110,
            sorter: columnSorter<ClientOutstanding>((row) => row.bill_count, 'number'),
            render: (count: number, row: ClientOutstanding) =>
                row.balance_only && count === 0
                    ? <Tag style={{ marginInlineEnd: 0 }}>Balance only</Tag>
                    : count,
        },
    ];

    return (
        <Space direction="vertical" size="middle" style={{ width: '100%' }}>
            <Space style={{ width: '100%', justifyContent: 'space-between', alignItems: 'flex-start' }} wrap>
                <Space direction="vertical" size={4}>
                    <Typography.Title level={4} style={{ margin: 0 }}>Client outstanding &amp; pending purchases</Typography.Title>
                    <Text type="secondary">
                        Every figure on this page is read from Tally. It is not the ERP&rsquo;s own invoice ledger.
                    </Text>
                </Space>

                {/* Drawn for finance.manage only — a courtesy, never the gate:
                    the endpoint 403s regardless of what this page shows. */}
                {mayImport && (
                    <Upload
                        accept={OUTSTANDING_IMPORT_ACCEPT}
                        showUploadList={false}
                        beforeUpload={(file) => {
                            // The browser reads nothing out of the XML; the
                            // whole file goes to the server, which owns the
                            // one reader for Tally's shape.
                            importPosition.mutate(file);
                            return false;
                        }}
                    >
                        <Button icon={<UploadOutlined />} loading={importPosition.isPending} disabled={importPosition.isPending}>
                            Import Tally export
                        </Button>
                    </Upload>
                )}
            </Space>

            {/* A 200 THAT CHANGED NOTHING LOOKS DIFFERENT FROM ONE THAT DID.
                Left standing until it is dismissed rather than flashed as a
                toast: "nothing usable in that file" is the message somebody
                must actually see, and it is the one a toast would eat. */}
            {importOutcome && (
                <Alert
                    type={importOutcome.tone}
                    showIcon
                    closable
                    onClose={() => setImportOutcome(null)}
                    title={importOutcome.text}
                />
            )}

            {/* THE HONESTY BANNER. The page is exactly as current as the last
                operator pull, and says which date it is showing rather than
                letting a stale position read as live. */}
            {data?.as_of != null && (
                <Alert
                    type="info"
                    showIcon
                    title={`Position as at ${data.as_of}${data.company ? ` — ${data.company}` : ''}`}
                    description={
                        data.synced_at
                            ? `Pulled from Tally on ${new Date(data.synced_at).toLocaleString()}. Figures change only when the Tally Sync Agent is asked to read again.`
                            : undefined
                    }
                />
            )}

            {hasBalanceOnly && (
                <Alert
                    type="warning"
                    showIcon
                    title="Tally supplied client balances without invoice detail"
                    description="The outstanding totals are available, but Tally did not include bill references or due dates in this pull. Bill counts, overdue totals and ageing are therefore not claimed for balance-only clients."
                />
            )}

            {nothingPulled && (
                <Alert
                    type="warning"
                    showIcon
                    title="No outstanding position has been pulled from Tally yet"
                    // The sentence that used to sit here sent the reader to the
                    // factory PC to press the tray button. It was written when
                    // that was the only road, and the Import control above is
                    // here precisely BECAUSE that road can be closed — a dark
                    // machine is the likeliest reason anyone is reading this.
                    // Pointing at it was the one instruction guaranteed not to
                    // work. Replaced with nothing rather than with a sentence
                    // about the button: the control is right there, and this
                    // page does not explain its own buttons.
                    description="This page has nothing to show yet. It does not fall back to the ERP's own invoices, which would report a fraction of the real position as the whole of it."
                />
            )}

            <Row gutter={[16, 16]}>
                <Col xs={24} sm={12} lg={6}>
                    <Card><Statistic title="Total outstanding" value={money(totals?.outstanding_amount ?? null)} loading={isPending} /></Card>
                </Col>
                <Col xs={24} sm={12} lg={6}>
                    <Card>
                        <Statistic
                            title="Overdue"
                            value={hasBalanceOnly ? (allBalancesOnly ? 'Not available' : 'Partial') : money(totals?.overdue_amount ?? null)}
                            loading={isPending}
                            valueStyle={{ color: !hasBalanceOnly && num(totals?.overdue_amount ?? '0') > 0 ? '#cf1322' : undefined }}
                        />
                    </Card>
                </Col>
                <Col xs={24} sm={12} lg={6}>
                    <Card><Statistic title="Pending purchases" value={money(totals?.pending_order_amount ?? null)} loading={isPending} /></Card>
                </Col>
                <Col xs={24} sm={12} lg={6}>
                    <Card><Statistic title="Clients" value={totals?.clients ?? 0} loading={isPending} /></Card>
                </Col>
            </Row>

            <Space wrap>
                <Input.Search
                    allowClear
                    placeholder="Search client or Tally ledger"
                    style={{ width: 280 }}
                    onChange={(e) => setSearch(e.target.value)}
                    value={search}
                />
                <Segmented
                    value={focus}
                    onChange={(v) => setFocus(v as Focus)}
                    options={[
                        { label: 'All clients', value: 'all' },
                        { label: 'Overdue only', value: 'overdue', disabled: hasBalanceOnly },
                        { label: 'Has pending orders', value: 'pending' },
                    ]}
                />
            </Space>

            <Table<ClientOutstanding>
                sticky={TABLE_STICKY}
                rowKey={(row) => row.party_ledger_guid ?? `name:${row.party_ledger_name}`}
                loading={isPending}
                dataSource={clients}
                columns={columns}
                size="small"
                scroll={{ x: 1500 }}
                pagination={{ defaultPageSize: 25, showSizeChanger: true }}
                locale={{ emptyText: <Empty description={nothingPulled ? 'Nothing pulled from Tally yet' : 'No client matches this filter'} /> }}
                expandable={{
                    // The bill-level detail is where "outstanding days" is
                    // actually actionable — a person chasing a client needs the
                    // bill reference, not just the client total.
                    expandedRowRender: (row) => (
                        <Space direction="vertical" size="middle" style={{ width: '100%' }}>
                            <Table<OutstandingBill>
                                rowKey={(bill, i) => `${bill.bill_reference ?? 'on-account'}-${i}`}
                                dataSource={row.bills}
                                size="small"
                                pagination={false}
                                title={() => <Text strong>Outstanding bills</Text>}
                                locale={{ emptyText: 'No outstanding bills' }}
                                columns={[
                                    { title: 'Bill', dataIndex: 'bill_reference', render: (v: string | null) => v ?? <Text type="secondary">On account</Text> },
                                    { title: 'Bill date', dataIndex: 'bill_date', render: (v: string | null) => v ?? '—' },
                                    { title: 'Due date', dataIndex: 'due_date', render: (v: string | null) => v ?? <Text type="secondary">No due date</Text> },
                                    {
                                        title: 'Outstanding days',
                                        dataIndex: 'days_past_due',
                                        align: 'right',
                                        // Null reads "—", never 0: 0 would mean "due today".
                                        render: (v: number | null) =>
                                            v === null ? <Text type="secondary">—</Text>
                                                : v <= 0 ? <Text type="secondary">not yet due</Text>
                                                    : <Tag color={overdueColor(v)}>{v}</Tag>,
                                    },
                                    { title: 'Amount', dataIndex: 'closing_amount', align: 'right', render: (v: string) => money(v) },
                                ]}
                            />

                            <Table<PendingOrderLine>
                                rowKey={(order, i) => `${order.order_reference ?? 'order'}-${i}`}
                                dataSource={row.pending_orders}
                                size="small"
                                pagination={false}
                                title={() => <Text strong>Pending purchases (client orders not yet shipped)</Text>}
                                locale={{ emptyText: 'No pending orders' }}
                                columns={[
                                    { title: 'Their PO', dataIndex: 'order_reference', render: (v: string | null) => v ?? '—' },
                                    { title: 'Order date', dataIndex: 'order_date', render: (v: string | null) => v ?? '—' },
                                    { title: 'Item', dataIndex: 'stock_item_name', render: (v: string | null) => v ?? '—' },
                                    {
                                        title: 'Pending qty',
                                        dataIndex: 'pending_quantity',
                                        align: 'right',
                                        render: (v: string | null, line: PendingOrderLine) =>
                                            v === null ? '—' : `${money(v)}${line.quantity_unit ? ` ${line.quantity_unit}` : ''}`,
                                    },
                                    {
                                        title: 'Pending value',
                                        dataIndex: 'pending_amount',
                                        align: 'right',
                                        // Tally stated no value: say so rather than showing 0.
                                        render: (v: string | null) => (v === null ? <Text type="secondary">not priced in Tally</Text> : money(v)),
                                    },
                                ]}
                            />
                        </Space>
                    ),
                    rowExpandable: (row) => row.bills.length > 0 || row.pending_orders.length > 0,
                }}
                summary={() =>
                    totals == null ? null : (
                        <Table.Summary fixed>
                            <Table.Summary.Row>
                                {/* THE EXPAND COLUMN. An expandable antd table
                                    renders an extra leading cell in every header
                                    and body row, but this summary row is written
                                    by hand — without this spacer every total
                                    renders one column to the LEFT of its heading
                                    (outstanding money printed under "Overdue"),
                                    with nothing throwing and nothing failing to
                                    typecheck. ClientOutstandingPage.render.test
                                    counts header cells against footer cells so
                                    it can never drift back. */}
                                <Table.Summary.Cell index={0} />
                                <Table.Summary.Cell index={1}><Text strong>Total ({clients.length} shown)</Text></Table.Summary.Cell>
                                <Table.Summary.Cell index={2} align="right"><Text strong>{money(totals.pending_order_amount)}</Text></Table.Summary.Cell>
                                <Table.Summary.Cell index={3} align="right"><Text strong>{money(totals.outstanding_amount)}</Text></Table.Summary.Cell>
                                <Table.Summary.Cell index={4} align="right"><Text strong>{money(totals.overdue_amount)}</Text></Table.Summary.Cell>
                                {/* Outstanding days: a total of ages is meaningless. */}
                                <Table.Summary.Cell index={5} />
                                {(Object.keys(BUCKET_LABELS) as AgeingBucket[]).map((bucket, i) => (
                                    <Table.Summary.Cell key={bucket} index={6 + i} align="right">
                                        <Text strong>{money(totals.ageing[bucket])}</Text>
                                    </Table.Summary.Cell>
                                ))}
                                <Table.Summary.Cell index={12} align="right">
                                    <Text strong>{hasBalanceOnly ? 'Partial' : totals.bill_count}</Text>
                                </Table.Summary.Cell>
                            </Table.Summary.Row>
                        </Table.Summary>
                    )
                }
            />

            <Text type="secondary" style={{ fontSize: 12 }}>
                Ageing bands count days past each bill&rsquo;s DUE date. Tally&rsquo;s own ageing screen counts days since the
                bill date, so the two reports will not agree column for column. Bills for which Tally states no due date are
                shown separately rather than being assumed current or overdue.
            </Text>
        </Space>
    );
}
