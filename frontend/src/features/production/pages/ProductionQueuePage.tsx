import { DownOutlined, UpOutlined } from '@ant-design/icons';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, Form, Input, Modal, Space, Table, Tag, Typography, message } from 'antd';
import { useState } from 'react';
import { hasManageAccess, hasModuleAccess } from '@/features/auth/permissions';
import { useAuthStore } from '@/features/auth/store';
import { planningBasisLine, planningEtaCell } from '@/features/inventory/planning';
import { apiRefusalMessage } from '@/features/material-flow/api';
import { formatQuantity } from '@/features/material-flow/words';
import {
    cancelProductionRequest,
    readProductionQueue,
    reorderProductionRequests,
    startProductionRequest,
} from '@/features/production/api';
import { queueEmptyText } from '@/features/production/queueEmptyText';
import { type ProductionQueueGroup, groupQueueByProduct, sumQuantities } from '@/features/production/queueGroups';
import type { ProductionQueueRow, ProductionRequestStatus } from '@/features/production/types';
import { formatDate } from '@/lib/datetime';
import { itemDisplayName } from '@/features/inventory/itemIdentity';
import { itemLabel } from '@/lib/itemLabel';
import { ListReadAlert } from '@/lib/ListEmpty';
import { TABLE_STICKY } from '@/lib/tableProps';

/**
 * THE FLOOR'S WORKLIST — what the store has asked the factory to make, in the
 * order the factory decided to make it, with who is waiting for it and when it
 * could be ready.
 *
 * GROUPED BY PRODUCT, because the factory runs a PRODUCT. Three customers
 * waiting on the same bottle is one setup and one mould; the parent row is the
 * product and the sub-rows are the customers, each keeping its own quantity,
 * its own date and its own buttons.
 *
 * NOTHING ON THIS PAGE TOUCHES A BATCH (invariant 2). "Start" records that a
 * person picked the job up; PEOPLE start batches, on the Shift Floor, and this
 * screen creates, starts and cancels none. Nor does it move stock (invariant
 * 1) — a request is a piece of paper about pieces and priority.
 *
 * NO ETA IS STORED (S11) and no date is invented. The dates come from the
 * planning walk on every read, and a row the walk could not date says so with
 * its reason rather than showing a blank that reads as "not yet due".
 *
 * NO MACHINE COLUMN, and that is an answer rather than an omission.
 * `production_requests` carries no machine and no shift: a machine is chosen
 * when somebody starts a BATCH, and this document starts none. There is no
 * evidenced source for "which machine will run this", so the column is not
 * drawn at all rather than filled with a plausible guess.
 *
 * TWO DESKS, TWO PERMISSIONS, ONE DOCUMENT (P3). The read is OR-gated
 * (production.view/manage OR inventory.view/manage), so a storekeeper can open
 * this page by URL and see what they asked for. What they cannot do is decide
 * the order or press Start: those are the floor's alone (production.manage).
 * Cancel is the two-sided one — the store when the customer no longer wants
 * it, the floor when it cannot be run — so it needs manage on EITHER side.
 * Read as two variables rather than one, the way ProductionQcPage does it.
 *
 * COLUMNS APPEAR WITH THEIR DATA, and their absence is a PERMISSION fact
 * rather than an empty factory (the floor-visibility owner question). The joined figures are each gated by the
 * module that owns them, and the server OMITS what a caller may not read — so
 * presence is decided once, from whether the key exists, and never per-cell
 * from a falsy check. `planning === undefined` means "not yours to read" and
 * draws no column; `cannot_estimate` means "nobody knows" and prints the
 * refusal. Conflating the two would show the factory refusing to estimate at
 * somebody who is merely not allowed to see the answer.
 *
 * UNPAGINATED, deliberately, server-side: reorder renumbers the WHOLE queue,
 * and nobody should be asked to reorder a list they can see one page of.
 */

const statusColor: Record<ProductionRequestStatus, string> = {
    queued: 'default',
    in_progress: 'blue',
    // Neither reaches this list — it carries open requests only — but a
    // lifecycle action answers with the row it just moved, so the map is
    // total rather than a lookup that can return undefined.
    produced: 'green',
    cancelled: 'red',
};

const statusLabel: Record<ProductionRequestStatus, string> = {
    queued: 'queued',
    in_progress: 'in progress',
    produced: 'produced',
    cancelled: 'cancelled',
};

const numeric = { fontVariantNumeric: 'tabular-nums' } as const;
const caption = { fontSize: 12, display: 'block' } as const;

/** A queue row as the table draws it: its position in the flat queue and the product run it belongs to. */
type QueueTableRow = ProductionQueueRow & { position: number; group: ProductionQueueGroup; firstInGroup: boolean };

/**
 * The whole queue's ids with one row moved a place — what reorder() is sent.
 *
 * THE WHOLE ORDERING, never a "move this one up" delta: priorities are dense
 * and the server rewrites all of them inside one locking transaction, so two
 * people reordering at once end with one of the two orders rather than an
 * interleaving of both.
 *
 * FLAT, over the server's own order — never the grouped view. Grouping is a
 * way of LOOKING at the queue; the queue itself is one ranked list, and the
 * indices sent back have to be indices into that list.
 */
export function movedOrder(ids: number[], index: number, direction: -1 | 1): number[] {
    const target = index + direction;
    if (index < 0 || index >= ids.length || target < 0 || target >= ids.length) return ids;

    const next = [...ids];
    [next[index], next[target]] = [next[target], next[index]];

    return next;
}

/** The ETA cell, shared verbatim with the planning dashboard — one wording, two screens. */
function EtaCell({ planning }: { planning: ProductionQueueRow['planning'] }) {
    if (planning === undefined) return null;

    const eta = planningEtaCell(planning);

    return (
        <Space direction="vertical" size={0}>
            {eta.dated ? (
                <span style={numeric}>{formatDate(eta.date)}</span>
            ) : (
                <Typography.Text type="warning">{eta.refusal}</Typography.Text>
            )}
            {eta.shifts !== null && (
                <Typography.Text type="secondary" style={caption}>
                    {eta.shifts}
                </Typography.Text>
            )}
        </Space>
    );
}

export default function ProductionQueuePage() {
    const queryClient = useQueryClient();
    const user = useAuthStore((state) => state.user);

    // The floor's own authority: the order of the queue and pressing Start.
    const canRunQueue = hasManageAccess(user, 'production');
    // The two-sided act. Either desk that can manage its own side may withdraw
    // a request — the OR gate on the route, read back here so a store user is
    // not offered a button their POST would 403 on.
    const canCancel = canRunQueue || hasManageAccess(user, 'inventory');
    // Who may be here at all. The page still renders for a store login; only
    // the floor's controls are absent.
    const canView = hasModuleAccess(user, 'production') || hasModuleAccess(user, 'inventory');

    const [cancelling, setCancelling] = useState<ProductionQueueRow | null>(null);
    const [reason, setReason] = useState('');
    /** Product groups this reader has shut. Everything not in here is open. */
    const [collapsed, setCollapsed] = useState<Set<string>>(new Set());

    /*
     * Keyed UNDER ['production', 'requests'] on purpose: four other screens
     * invalidate that prefix when they change the queue, and this read is the
     * same queue. A key of its own would leave this page stale after a hold
     * was placed or a request withdrawn somewhere else.
     */
    const { data, isLoading, isPending, isError, error, refetch } = useQuery({
        queryKey: ['production', 'requests', 'queue'],
        queryFn: readProductionQueue,
        enabled: canView,
    });

    /*
     * ONE ACT, FOUR SCREENS — the same invalidation the store's fulfilment
     * page does, for the same reason: a request started or withdrawn here
     * changes the store's queue row, the planning ETA and the order's badge.
     */
    const refresh = () => {
        queryClient.invalidateQueries({ queryKey: ['production', 'requests'] });
        queryClient.invalidateQueries({ queryKey: ['inventory', 'fulfilment'] });
        queryClient.invalidateQueries({ queryKey: ['sales', 'sales-orders'] });
    };

    const refuse = (fallback: string) => (error: unknown) => message.error(apiRefusalMessage(error, fallback));

    const reorderMutation = useMutation({
        mutationFn: reorderProductionRequests,
        onSuccess: refresh,
        onError: refuse('The queue order was refused.'),
    });

    const startMutation = useMutation({
        mutationFn: startProductionRequest,
        onSuccess: (request) => {
            // Said plainly, because the word "start" on a factory screen means
            // a batch everywhere else in this app and does not here.
            message.success(`${request.request_number} picked up. No batch has been started.`);
            refresh();
        },
        onError: refuse('The request could not be picked up.'),
    });

    const cancelMutation = useMutation({
        mutationFn: () => cancelProductionRequest((cancelling as ProductionQueueRow).id, reason),
        onSuccess: () => {
            message.success('Request withdrawn. Its reason stays on the row.');
            refresh();
            setCancelling(null);
            setReason('');
        },
        onError: refuse('The withdrawal was refused.'),
    });

    const rows = data?.data ?? [];
    const groups = groupQueueByProduct(rows);
    // THE FLAT ORDER, for reorder. The rows are the real queue; the product
    // run above each row is only how it is being read.
    const ids = rows.map((row) => row.id);

    // The floor-visibility owner question: what this login may read, decided ONCE from the payload's shape.
    const showsPlanning = rows.some((row) => row.planning !== undefined);
    const showsDemand = rows.some((row) => row.ordered !== undefined);
    const showsExpected = rows.some((row) => row.sales_order !== null && 'expected_date' in row.sales_order);

    /*
     * ONE table, not a table per product (03-Sep-2026). The previous shape
     * nested a full sub-table under every product row: a navy header
     * repeated per product, inner and outer columns that never lined up, and
     * Start/Cancel pushed off the right edge. Now each request is one row in
     * the server's order, and the product run it belongs to is a single
     * merged cell spanning its rows — the same grouping, read in one grid.
     */
    const tableRows: QueueTableRow[] = groups.flatMap((group) =>
        group.rows.map((row, index) => ({
            ...row,
            position: ids.indexOf(row.id) + 1,
            group,
            firstInGroup: index === 0,
        })),
    );

    const productCell = (group: ProductionQueueGroup) => (
        <Space direction="vertical" size={0}>
            {/* The ERP's own label when the factory set one — that is what
                display_name is for — with Tally's wire name as the fallback. */}
            <strong>
                {itemLabel(
                    group.item === null
                        ? null
                        : {
                            sku: group.item.sku,
                            name: itemDisplayName({
                                name: group.item.name ?? '',
                                display_name: group.item.display_name ?? null,
                            }),
                        },
                )}
            </strong>
            <Typography.Text type="secondary" style={caption}>
                {group.rows.length} order{group.rows.length === 1 ? '' : 's'} · <span style={numeric}>{formatQuantity(group.quantity)}</span> to make
            </Typography.Text>
            {showsPlanning && (
                // ITEM-level, taken once. Summing it across the rows would
                // multiply the factory's real finished stock by the number
                // of customers.
                <Typography.Text type="secondary" style={caption}>
                    <span style={numeric}>{formatQuantity(group.free)}</span> free in FG
                </Typography.Text>
            )}
            {/* The run's date when every job in it has one; a refusal is said once, on the job. */}
            {showsPlanning && !group.cannot_estimate && group.estimated_ready_date !== null && (
                <Typography.Text type="secondary" style={caption}>
                    Could be ready <span style={numeric}>{formatDate(group.estimated_ready_date)}</span>
                </Typography.Text>
            )}
        </Space>
    );

    return (
        <>
            <div className="queue-head">
                <Typography.Title level={3} style={{ margin: 0 }}>
                    Production Queue
                </Typography.Title>
                {rows.length > 0 && (
                    <Typography.Text type="secondary" className="queue-count" style={numeric}>
                        {rows.length} job{rows.length === 1 ? '' : 's'} · {groups.length} product{groups.length === 1 ? '' : 's'} ·{' '}
                        {formatQuantity(sumQuantities(rows.map((row) => row.quantity)))} to make
                    </Typography.Text>
                )}
            </div>

            {/* A refused read is said as a refusal, never as an empty queue. */}
            <ListReadAlert state={{ isPending, isError, error, refetch }} entity="the production queue" />

            <Table<QueueTableRow>
                className="queue-table"
                scroll={{ x: 'max-content' }}
                sticky={TABLE_STICKY}
                rowKey="id"
                loading={isLoading}
                dataSource={tableRows}
                // The server's order IS the queue. No sorter on any column:
                // one would let a reader rearrange the thing the reorder
                // buttons write, and then write back what they were looking at.
                pagination={false}
                locale={{ emptyText: queueEmptyText(isError, error) }}
                columns={[
                    {
                        // The queue position: a true sequence, so it is numbered.
                        title: '#',
                        align: 'right',
                        width: 64,
                        render: (_, row) => <span className="queue-position">{row.position}</span>,
                    },
                    {
                        title: 'Product',
                        onCell: (row) => ({ rowSpan: row.firstInGroup ? row.group.rows.length : 0 }),
                        render: (_, row) => productCell(row.group),
                    },
                    {
                        title: 'Request',
                        render: (_, row) => (
                            <Space direction="vertical" size={0}>
                                <strong>{row.request_number}</strong>
                                <Typography.Text type="secondary" style={caption}>
                                    {row.sales_order
                                        ? `${row.sales_order.document_number} · ${row.sales_order.customer_name ?? '—'}`
                                        : '—'}
                                </Typography.Text>
                            </Space>
                        ),
                    },
                    {
                        title: 'To make',
                        align: 'right',
                        render: (_, row) => <span style={numeric}>{formatQuantity(row.quantity)}</span>,
                    },
                    ...(showsDemand
                        ? [
                              {
                                  title: 'Ordered',
                                  align: 'right' as const,
                                  render: (_: unknown, row: QueueTableRow) => (
                                      <Space direction="vertical" size={0}>
                                          <span style={numeric}>{formatQuantity(row.ordered)}</span>
                                          <Typography.Text type="secondary" style={caption}>
                                              <span style={numeric}>{formatQuantity(row.delivered)}</span> delivered
                                          </Typography.Text>
                                      </Space>
                                  ),
                              },
                          ]
                        : []),
                    ...(showsExpected
                        ? [
                              {
                                  // Called what Sales calls it. Whether it is a
                                  // promise to the customer has never been
                                  // recorded (the floor-visibility owner question), so this screen does not say so.
                                  title: 'Expected',
                                  render: (_: unknown, row: QueueTableRow) => (
                                      <span style={numeric}>{formatDate(row.sales_order?.expected_date)}</span>
                                  ),
                              },
                          ]
                        : []),
                    ...(showsPlanning
                        ? [
                              {
                                  title: 'Could be ready',
                                  render: (_: unknown, row: QueueTableRow) => <EtaCell planning={row.planning} />,
                              },
                              {
                                  title: 'Jobs ahead',
                                  align: 'right' as const,
                                  render: (_: unknown, row: QueueTableRow) => (
                                      <span style={numeric}>{row.planning?.queued_ahead ?? '—'}</span>
                                  ),
                              },
                          ]
                        : []),
                    {
                        title: 'Status',
                        render: (_, row) => <Tag color={statusColor[row.status]}>{statusLabel[row.status] ?? row.status}</Tag>,
                    },
                    {
                        // The arrows move a row through the FLAT queue, so the
                        // index is its position in the server's list.
                        title: 'Order',
                        render: (_, row) => {
                            if (!canRunQueue) return <Typography.Text type="secondary">—</Typography.Text>;

                            const index = ids.indexOf(row.id);

                            return (
                                <Space size={4}>
                                    <Button
                                        size="small"
                                        icon={<UpOutlined />}
                                        aria-label="Move up"
                                        disabled={index <= 0 || !row.can.reorder || reorderMutation.isPending}
                                        onClick={() => reorderMutation.mutate(movedOrder(ids, index, -1))}
                                    />
                                    <Button
                                        size="small"
                                        icon={<DownOutlined />}
                                        aria-label="Move down"
                                        disabled={
                                            index === -1 ||
                                            index === ids.length - 1 ||
                                            !row.can.reorder ||
                                            reorderMutation.isPending
                                        }
                                        onClick={() => reorderMutation.mutate(movedOrder(ids, index, 1))}
                                    />
                                </Space>
                            );
                        },
                    },
                    {
                        title: 'Actions',
                        fixed: 'right',
                        render: (_, row) => (
                            <Space>
                                {canRunQueue && row.can.start && (
                                    <Button size="small" type="primary" loading={startMutation.isPending} onClick={() => startMutation.mutate(row.id)}>
                                        Start
                                    </Button>
                                )}
                                {canCancel && row.can.cancel && (
                                    <Button
                                        size="small"
                                        danger
                                        onClick={() => {
                                            setCancelling(row);
                                            setReason('');
                                        }}
                                    >
                                        Cancel
                                    </Button>
                                )}
                            </Space>
                        ),
                    },
                ]}
            />

            {/* The figures those dates stand on — the same footer the planning
                dashboard prints, and figures rather than a paragraph. */}
            {showsPlanning && data !== undefined && (
                <Typography.Text type="secondary" style={{ display: 'block', marginTop: 8 }}>
                    {planningBasisLine(data.basis)}
                </Typography.Text>
            )}

            <Modal
                maskClosable={false}
                title={cancelling ? `Cancel ${cancelling.request_number}` : 'Cancel request'}
                open={cancelling !== null}
                onCancel={() => setCancelling(null)}
                onOk={() => cancelMutation.mutate()}
                okButtonProps={{ danger: true, disabled: reason.trim().length < 3 }}
                confirmLoading={cancelMutation.isPending}
                destroyOnHidden
            >
                <Form layout="vertical">
                    {/* Required by the server, and kept on the row: a
                        withdrawn request keeps its paper and its reason. */}
                    <Form.Item label="Reason">
                        <Input value={reason} onChange={(event) => setReason(event.target.value)} autoFocus />
                    </Form.Item>
                </Form>
            </Modal>
        </>
    );
}
