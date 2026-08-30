import { useQuery } from '@tanstack/react-query';
import { Alert, Input, Space, Table, Tag, Tooltip, Typography } from 'antd';
import { useMemo, useState } from 'react';
import { listFulfilmentControl } from '@/features/sales/api';
import { NOT_RECORDED } from '@/features/sales/types';
import type { FulfilmentControlRow } from '@/features/sales/types';
import { formatQuantity } from '@/features/material-flow/words';
import { itemLabel } from '@/lib/itemLabel';

/**
 * THE SALES FULFILMENT CONTROL VIEW — one row per sales order line, and the
 * one board Sales, Store, Production, Quality and Accounts all read.
 *
 * It exists to answer five questions at a glance, for a factory user who is
 * not going to read five screens to assemble them: what is the status, what is
 * blocking this line, WHO MUST ACT NEXT, how much stock is available or held,
 * and when it can go.
 *
 * IT IS A READ, AND ONLY A READ. There is no button here. Holding, releasing,
 * re-pointing and asking the floor stay on the store's fulfilment queue;
 * starting stays on the production queue; dispatching and invoicing stay on
 * Sales. This board is where the teams agree on what is true before each acts
 * on its own screen — one shared state, five sets of eyes.
 *
 * THE SERVER DECIDES THE ORDER (rows arrive sorted by who needs a human
 * soonest, over-promised stock first) so no column carries a `sorter`: one
 * that did would re-sort the rows in front of the reader and quietly defeat
 * the ordering the server computed across the whole board.
 *
 * AND IT NEVER SHOWS A FALSE GREEN. Five fields have no source in this build —
 * the store's rejected quantity, planned and completed production, internal QA
 * approval, and customer approval. Each arrives as the string 'not_recorded'
 * with a sentence saying why, and each is rendered AS THAT SENTENCE. A blank
 * cell on a factory floor reads as "nothing to worry about", and for these
 * five that would be the most expensive thing this page could say.
 */

/** The blocker's tone — red where somebody is holding the order up. */
function blockerTone(code: string): string {
    switch (code) {
        case 'over_reserved':
            return 'red';
        case 'short_and_not_requested':
            return 'volcano';
        case 'store_has_not_held_stock':
            return 'orange';
        case 'queued_for_production':
            return 'gold';
        case 'in_production':
            return 'blue';
        case 'held_awaiting_dispatch':
            return 'cyan';
        case 'awaiting_invoice':
            return 'purple';
        default:
            return 'default';
    }
}

/**
 * The one renderer every unbuilt field goes through, so none of them can
 * accidentally become a blank or a zero. Shows the words, and hangs the
 * server's own reason off a tooltip rather than burying it.
 */
function NotRecordedCell({ detail }: { detail: string }) {
    return (
        <Tooltip title={detail}>
            <Tag color="default" style={{ borderStyle: 'dashed' }}>
                not recorded
            </Tag>
        </Tooltip>
    );
}

export default function FulfilmentControlPage() {
    const [search, setSearch] = useState('');

    const controlQuery = useQuery({
        queryKey: ['sales', 'fulfilment-control'],
        queryFn: listFulfilmentControl,
    });

    const rows = useMemo(() => {
        const all = controlQuery.data ?? [];
        const term = search.trim().toLowerCase();
        if (!term) return all;
        return all.filter((row) =>
            [row.customer?.name, row.item?.sku, row.item?.name, row.item?.display_name, row.blocker.team]
                .filter(Boolean)
                .some((value) => String(value).toLowerCase().includes(term)),
        );
    }, [controlQuery.data, search]);

    return (
        <Space direction="vertical" size="middle" style={{ width: '100%' }}>
            <Typography.Title level={3} style={{ marginBottom: 0 }}>
                Sales fulfilment control
            </Typography.Title>
            <Typography.Paragraph type="secondary" style={{ marginBottom: 0 }}>
                Every line of every live order — what is blocking it and who must act next. This board is
                read-only: hold and release on Store Fulfilment, start on the Production Queue, dispatch and
                invoice on Sales.
            </Typography.Paragraph>

            {/*
              Said ONCE, at the top, rather than repeated in five columns: these
              two gates are not recorded anywhere in the ERP. A person reading
              this board must not conclude from a covered line that it may ship.
            */}
            <Alert
                type="warning"
                showIcon
                message="Internal QA approval and customer approval are not recorded in this ERP"
                description={
                    'A line shown as held in full is stock the store has set aside — it is NOT a statement that the '
                    + 'goods may leave. Both approvals must be confirmed off-system before dispatch, and the '
                    + 'planned/completed production and store-rejected columns have no source in this build either.'
                }
            />

            <Input.Search
                allowClear
                placeholder="Customer, product or team"
                value={search}
                onChange={(event) => setSearch(event.target.value)}
                style={{ maxWidth: 360 }}
            />

            <Table<FulfilmentControlRow>
                rowKey="line_id"
                loading={controlQuery.isPending}
                dataSource={rows}
                scroll={{ x: 'max-content' }}
                sticky={{ offsetHeader: 64 }}
                pagination={false}
                columns={[
                    {
                        title: 'Blocker',
                        key: 'blocker',
                        fixed: 'left',
                        render: (_, row) => (
                            <Tooltip title={row.blocker.summary}>
                                <Tag color={blockerTone(row.blocker.code)}>{row.blocker.code.replace(/_/g, ' ')}</Tag>
                            </Tooltip>
                        ),
                    },
                    {
                        title: 'Who acts next',
                        key: 'team',
                        render: (_, row) => <strong>{row.blocker.team}</strong>,
                    },
                    {
                        title: 'Customer',
                        key: 'customer',
                        render: (_, row) => row.customer?.name ?? '—',
                    },
                    {
                        title: 'Product',
                        key: 'item',
                        render: (_, row) => (row.item ? itemLabel(row.item) : '—'),
                    },
                    {
                        title: 'Ordered',
                        key: 'ordered',
                        align: 'right',
                        render: (_, row) => formatQuantity(row.ordered, row.item?.uom),
                    },
                    {
                        title: 'Available',
                        key: 'available',
                        align: 'right',
                        render: (_, row) => formatQuantity(row.available_stock, row.item?.uom),
                    },
                    {
                        title: 'Held',
                        key: 'held',
                        align: 'right',
                        render: (_, row) => formatQuantity(row.held, row.item?.uom),
                    },
                    {
                        title: 'Store rejected',
                        key: 'store_rejected',
                        render: (_, row) =>
                            row.store.rejected === NOT_RECORDED ? (
                                <NotRecordedCell detail={row.store.rejected_detail} />
                            ) : (
                                formatQuantity(row.store.rejected, row.item?.uom)
                            ),
                    },
                    {
                        title: 'Waiting',
                        key: 'waiting',
                        align: 'right',
                        // THE AGEING SIGNAL: how long the store has sat on a hold.
                        render: (_, row) =>
                            row.store.waiting_days === null ? (
                                '—'
                            ) : (
                                <Tag color={row.store.waiting_days >= 3 ? 'red' : 'default'}>
                                    {row.store.waiting_days}d
                                </Tag>
                            ),
                    },
                    {
                        title: 'Shortfall',
                        key: 'shortfall',
                        align: 'right',
                        render: (_, row) => formatQuantity(row.shortfall, row.item?.uom),
                    },
                    {
                        title: 'Production',
                        key: 'production',
                        render: (_, row) =>
                            row.production.status
                                ? `${row.production.status.replace(/_/g, ' ')} · ${formatQuantity(row.production.requested, row.item?.uom)}`
                                : '—',
                    },
                    {
                        title: 'Planned',
                        key: 'planned',
                        render: (_, row) => <NotRecordedCell detail={row.production.planned_detail} />,
                    },
                    {
                        title: 'Completed',
                        key: 'completed',
                        render: (_, row) => <NotRecordedCell detail={row.production.completed_detail} />,
                    },
                    {
                        title: 'Internal QA',
                        key: 'qa',
                        render: (_, row) => <NotRecordedCell detail={row.quality.detail} />,
                    },
                    {
                        title: 'Customer approval',
                        key: 'customer_approval',
                        render: (_, row) => <NotRecordedCell detail={row.customer_approval.detail} />,
                    },
                    {
                        title: 'Dispatch ready',
                        key: 'dispatch_ready',
                        align: 'right',
                        render: (_, row) => formatQuantity(row.dispatch_ready, row.item?.uom),
                    },
                    {
                        title: 'Delivered',
                        key: 'delivered',
                        align: 'right',
                        render: (_, row) => formatQuantity(row.delivered, row.item?.uom),
                    },
                    {
                        title: 'Invoiced',
                        key: 'invoiced',
                        align: 'right',
                        render: (_, row) => formatQuantity(row.invoiced, row.item?.uom),
                    },
                    {
                        title: 'Expected',
                        key: 'expected',
                        render: (_, row) => row.expected_date ?? '—',
                    },
                ]}
            />
        </Space>
    );
}
