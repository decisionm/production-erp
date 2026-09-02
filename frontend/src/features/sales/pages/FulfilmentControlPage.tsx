import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Button, Input, Popconfirm, Space, Table, Tag, Tooltip, Typography, message } from 'antd';
import { useMemo, useState } from 'react';
import { approveDispatchQuality, listFulfilmentControl, revokeDispatchQuality } from '@/features/sales/api';
import { NOT_RECORDED } from '@/features/sales/types';
import type { FulfilmentControlRow } from '@/features/sales/types';
import { formatQuantity } from '@/features/material-flow/words';
import { filterOptions, onFilterBy } from '@/lib/clientSort';
import { itemLabel } from '@/lib/itemLabel';
import { apiRefusalMessage } from '@/features/material-flow/api';
import { hasManageAccess } from '@/features/auth/permissions';
import { useAuthStore } from '@/features/auth/store';

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
 * the ordering the server computed across the whole board. Column FILTERS
 * (Blocker, Who acts next, Production, Internal QA) narrow the board and
 * leave that order intact — the whole board is here, so they are honest.
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
        case 'awaiting_quality_approval':
            return 'magenta';
        case 'ready_to_dispatch':
            return 'green';
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
    const queryClient = useQueryClient();

    // THE ONE ACT ON THIS BOARD, and it is Quality's. Everything else still
    // lives on the screen that owns it — holding on Store Fulfilment, starting
    // on the Production Queue, dispatching and invoicing on Sales. Quality's
    // sign-off is here because this is the board that says "Quality" in the
    // "who acts next" column, and sending them somewhere else to act on a row
    // they are already looking at is how a gate gets skipped.
    const user = useAuthStore((state) => state.user);
    const canApprove = hasManageAccess(user, 'quality');

    const controlQuery = useQuery({
        queryKey: ['sales', 'fulfilment-control'],
        queryFn: listFulfilmentControl,
    });

    const refresh = () => queryClient.invalidateQueries({ queryKey: ['sales', 'fulfilment-control'] });

    const approve = useMutation({
        mutationFn: (lineId: number) => approveDispatchQuality(lineId),
        onSuccess: () => {
            void message.success('Quality approval recorded');
            void refresh();
        },
        // The 422 IS the answer — the server refuses on figures recomputed
        // under a lock, and its sentence carries the real number.
        onError: (error) => void message.error(apiRefusalMessage(error, 'Could not record the approval')),
    });

    const revoke = useMutation({
        mutationFn: (lineId: number) => revokeDispatchQuality(lineId),
        onSuccess: () => {
            void message.success('Quality approval withdrawn');
            void refresh();
        },
        onError: (error) => void message.error(apiRefusalMessage(error, 'Could not withdraw the approval')),
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
                type="info"
                showIcon
                message="Stock held is not permission to dispatch"
                description={
                    'Held stock is what the store has set aside. Nothing may leave until internal Quality signs the '
                    + 'line off, and dispatch is then capped at the quantity they approved. Planned and completed '
                    + 'production and the store-rejected quantity still have no source in this build and say so.'
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
                        filters: filterOptions(rows, (row) => row.blocker.code, (code) => String(code).replace(/_/g, ' ')),
                        onFilter: onFilterBy((row: FulfilmentControlRow) => row.blocker.code),
                        render: (_, row) => (
                            <Tooltip title={row.blocker.summary}>
                                <Tag color={blockerTone(row.blocker.code)}>{row.blocker.code.replace(/_/g, ' ')}</Tag>
                            </Tooltip>
                        ),
                    },
                    {
                        title: 'Who acts next',
                        key: 'team',
                        filters: filterOptions(rows, (row) => row.blocker.team),
                        onFilter: onFilterBy((row: FulfilmentControlRow) => row.blocker.team),
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
                        filters: filterOptions(rows, (row) => row.production.status, (status) => String(status).replace(/_/g, ' ')),
                        onFilter: onFilterBy((row: FulfilmentControlRow) => row.production.status),
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
                        // The two words the cell shows, never the raw state.
                        filters: filterOptions(rows, (row) => (row.quality.state === 'approved' ? 'approved' : 'pending')),
                        onFilter: onFilterBy((row: FulfilmentControlRow) => (row.quality.state === 'approved' ? 'approved' : 'pending')),
                        render: (_, row) =>
                            row.quality.state === 'approved' ? (
                                <Tooltip
                                    title={`Approved${row.quality.approved_by ? ` by ${row.quality.approved_by}` : ''}${
                                        row.quality.approved_at ? ` on ${row.quality.approved_at}` : ''
                                    }${row.quality.note ? ` — ${row.quality.note}` : ''}`}
                                >
                                    <Tag color="green">approved</Tag>
                                </Tooltip>
                            ) : (
                                <Tooltip title={row.quality.detail}>
                                    <Tag color="orange">pending</Tag>
                                </Tooltip>
                            ),
                    },
                    {
                        // TWO COLUMNS, NOT ONE. "Stock held" is what the store
                        // set aside; "Dispatch ready" is what may actually go,
                        // and it stays 0 until Quality signs off. A single
                        // figure conflated the two and read as permission.
                        title: 'Stock held',
                        key: 'stock_held',
                        align: 'right',
                        render: (_, row) => formatQuantity(row.stock_held, row.item?.uom),
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
                    ...(canApprove
                        ? [
                              {
                                  title: 'Quality action',
                                  key: 'quality_action',
                                  fixed: 'right' as const,
                                  render: (_: unknown, row: FulfilmentControlRow) =>
                                      row.quality.state === 'approved' ? (
                                          <Popconfirm
                                              title="Withdraw this approval?"
                                              description="Refused by the server once anything has been dispatched."
                                              onConfirm={() => revoke.mutate(row.line_id)}
                                          >
                                              <Button size="small" danger loading={revoke.isPending}>
                                                  Withdraw
                                              </Button>
                                          </Popconfirm>
                                      ) : (
                                          <Button
                                              size="small"
                                              type="primary"
                                              loading={approve.isPending}
                                              onClick={() => approve.mutate(row.line_id)}
                                          >
                                              Approve
                                          </Button>
                                      ),
                              },
                          ]
                        : []),
                ]}
            />
        </Space>
    );
}
