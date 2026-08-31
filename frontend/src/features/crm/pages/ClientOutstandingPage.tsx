import { useQuery } from '@tanstack/react-query';
import { Alert, Card, Col, Empty, Input, Row, Segmented, Space, Statistic, Table, Tag, Tooltip, Typography } from 'antd';
import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { getClientOutstanding } from '@/features/crm/api';
import type { AgeingBucket, ClientOutstanding, OutstandingBill, PendingOrderLine } from '@/features/crm/types';

const { Text } = Typography;

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

export default function ClientOutstandingPage() {
    const { data, isLoading, isError, error } = useQuery({
        queryKey: ['crm', 'client-outstanding'],
        queryFn: getClientOutstanding,
    });

    const [search, setSearch] = useState('');
    const [focus, setFocus] = useState<Focus>('all');

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

    if (isError) {
        return <Alert type="error" showIcon message="Could not load the outstanding position" description={(error as Error)?.message} />;
    }

    const totals = data?.totals;
    const nothingPulled = !isLoading && (data?.as_of ?? null) === null;

    const columns = [
        {
            title: 'Client',
            key: 'client',
            fixed: 'left' as const,
            width: 260,
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
            title: BUCKET_LABELS[bucket],
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
            width: 80,
        },
    ];

    return (
        <Space direction="vertical" size="middle" style={{ width: '100%' }}>
            <Space direction="vertical" size={4}>
                <Typography.Title level={4} style={{ margin: 0 }}>Client outstanding &amp; pending purchases</Typography.Title>
                <Text type="secondary">
                    Every figure on this page is read from Tally. It is not the ERP&rsquo;s own invoice ledger.
                </Text>
            </Space>

            {/* THE HONESTY BANNER. The page is exactly as current as the last
                operator pull, and says which date it is showing rather than
                letting a stale position read as live. */}
            {data?.as_of != null && (
                <Alert
                    type="info"
                    showIcon
                    message={`Position as at ${data.as_of}${data.company ? ` — ${data.company}` : ''}`}
                    description={
                        data.synced_at
                            ? `Pulled from Tally on ${new Date(data.synced_at).toLocaleString()}. Figures change only when the Tally Sync Agent is asked to read again.`
                            : undefined
                    }
                />
            )}

            {nothingPulled && (
                <Alert
                    type="warning"
                    showIcon
                    message="No outstanding position has been pulled from Tally yet"
                    description="Ask the operator to press “Read outstandings” in the Tally Sync Agent tray on the factory PC. Until then this page has nothing to show — it does not fall back to the ERP's own invoices, which would report a fraction of the real position as the whole of it."
                />
            )}

            <Row gutter={[16, 16]}>
                <Col xs={24} sm={12} lg={6}>
                    <Card><Statistic title="Total outstanding" value={money(totals?.outstanding_amount ?? null)} loading={isLoading} /></Card>
                </Col>
                <Col xs={24} sm={12} lg={6}>
                    <Card><Statistic title="Overdue" value={money(totals?.overdue_amount ?? null)} loading={isLoading} valueStyle={{ color: num(totals?.overdue_amount ?? '0') > 0 ? '#cf1322' : undefined }} /></Card>
                </Col>
                <Col xs={24} sm={12} lg={6}>
                    <Card><Statistic title="Pending purchases" value={money(totals?.pending_order_amount ?? null)} loading={isLoading} /></Card>
                </Col>
                <Col xs={24} sm={12} lg={6}>
                    <Card><Statistic title="Clients" value={totals?.clients ?? 0} loading={isLoading} /></Card>
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
                        { label: 'Overdue only', value: 'overdue' },
                        { label: 'Has pending orders', value: 'pending' },
                    ]}
                />
            </Space>

            <Table<ClientOutstanding>
                rowKey={(row) => row.party_ledger_guid ?? `name:${row.party_ledger_name}`}
                loading={isLoading}
                dataSource={clients}
                columns={columns}
                size="small"
                scroll={{ x: 1500 }}
                pagination={{ pageSize: 25, showSizeChanger: true }}
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
                }}
                summary={() =>
                    totals == null ? null : (
                        <Table.Summary fixed>
                            <Table.Summary.Row>
                                <Table.Summary.Cell index={0}><Text strong>Total ({clients.length} shown)</Text></Table.Summary.Cell>
                                <Table.Summary.Cell index={1} align="right"><Text strong>{money(totals.pending_order_amount)}</Text></Table.Summary.Cell>
                                <Table.Summary.Cell index={2} align="right"><Text strong>{money(totals.outstanding_amount)}</Text></Table.Summary.Cell>
                                <Table.Summary.Cell index={3} align="right"><Text strong>{money(totals.overdue_amount)}</Text></Table.Summary.Cell>
                                <Table.Summary.Cell index={4} />
                                {(Object.keys(BUCKET_LABELS) as AgeingBucket[]).map((bucket, i) => (
                                    <Table.Summary.Cell key={bucket} index={5 + i} align="right">
                                        <Text strong>{money(totals.ageing[bucket])}</Text>
                                    </Table.Summary.Cell>
                                ))}
                                <Table.Summary.Cell index={11} align="right"><Text strong>{totals.bill_count}</Text></Table.Summary.Cell>
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
