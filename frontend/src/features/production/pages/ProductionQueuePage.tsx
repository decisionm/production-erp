import { DownOutlined, UpOutlined } from '@ant-design/icons';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, Form, Input, Modal, Space, Table, Tag, Typography, message } from 'antd';
import { useState } from 'react';
import { hasManageAccess, hasModuleAccess } from '@/features/auth/permissions';
import { useAuthStore } from '@/features/auth/store';
import { apiRefusalMessage } from '@/features/material-flow/api';
import { formatQuantity } from '@/features/material-flow/words';
import {
    cancelProductionRequest,
    listProductionRequests,
    reorderProductionRequests,
    startProductionRequest,
} from '@/features/production/api';
import type { ProductionRequest, ProductionRequestStatus } from '@/features/production/types';
import { itemLabel } from '@/lib/itemLabel';

/**
 * THE FLOOR'S WORKLIST — what the store has asked the factory to make, in the
 * order the factory decided to make it.
 *
 * NOTHING ON THIS PAGE TOUCHES A BATCH (invariant 2). "Start" records that a
 * person picked the job up; PEOPLE start batches, on the Shift Floor, and this
 * screen creates, starts and cancels none. Nor does it move stock (invariant
 * 1) — a request is a piece of paper about pieces and priority.
 *
 * TWO DESKS, TWO PERMISSIONS, ONE DOCUMENT (P3). The read is OR-gated
 * (production.view/manage OR inventory.view/manage), so a storekeeper can open
 * this page by URL and see what they asked for. What they cannot do is decide
 * the order or press Start: those are the floor's alone (production.manage).
 * Cancel is the two-sided one — the store when the customer no longer wants
 * it, the floor when it cannot be run — so it needs manage on EITHER side.
 * Read as two variables rather than one, the way ProductionQcPage does it.
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

/**
 * The whole queue's ids with one row moved a place — what reorder() is sent.
 *
 * THE WHOLE ORDERING, never a "move this one up" delta: priorities are dense
 * and the server rewrites all of them inside one locking transaction, so two
 * people reordering at once end with one of the two orders rather than an
 * interleaving of both.
 */
export function movedOrder(ids: number[], index: number, direction: -1 | 1): number[] {
    const target = index + direction;
    if (index < 0 || index >= ids.length || target < 0 || target >= ids.length) return ids;

    const next = [...ids];
    [next[index], next[target]] = [next[target], next[index]];

    return next;
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

    const [cancelling, setCancelling] = useState<ProductionRequest | null>(null);
    const [reason, setReason] = useState('');

    const { data, isLoading, isError } = useQuery({
        queryKey: ['production', 'requests', 'queue'],
        queryFn: listProductionRequests,
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
        mutationFn: () => cancelProductionRequest((cancelling as ProductionRequest).id, reason),
        onSuccess: () => {
            message.success('Request withdrawn. Its reason stays on the row.');
            refresh();
            setCancelling(null);
            setReason('');
        },
        onError: refuse('The withdrawal was refused.'),
    });

    const rows = data ?? [];
    const ids = rows.map((row) => row.id);

    return (
        <>
            <Typography.Title level={3} style={{ marginTop: 0 }}>Production Queue</Typography.Title>

            <Table<ProductionRequest>
                scroll={{ x: 'max-content' }}
                rowKey="id"
                loading={isLoading}
                dataSource={rows}
                // The server's order IS the queue. No sorter on any column:
                // one would let a reader rearrange the thing the reorder
                // buttons write, and then write back what they were looking at.
                pagination={false}
                locale={{ emptyText: isError ? 'The queue could not be read.' : 'Nothing is queued for the floor.' }}
                columns={[
                    {
                        title: '#',
                        align: 'right',
                        render: (_, row) => <span style={numeric}>{row.priority}</span>,
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
                    { title: 'Item', render: (_, row) => itemLabel(row.item) },
                    { title: 'Quantity', align: 'right', render: (_, row) => <span style={numeric}>{formatQuantity(row.quantity)}</span> },
                    {
                        title: 'Status',
                        render: (_, row) => <Tag color={statusColor[row.status]}>{statusLabel[row.status] ?? row.status}</Tag>,
                    },
                    {
                        title: 'Order',
                        render: (_, row, index) =>
                            canRunQueue ? (
                                <Space size={4}>
                                    <Button
                                        size="small"
                                        icon={<UpOutlined />}
                                        aria-label="Move up"
                                        disabled={index === 0 || !row.can.reorder || reorderMutation.isPending}
                                        onClick={() => reorderMutation.mutate(movedOrder(ids, index, -1))}
                                    />
                                    <Button
                                        size="small"
                                        icon={<DownOutlined />}
                                        aria-label="Move down"
                                        disabled={index === rows.length - 1 || !row.can.reorder || reorderMutation.isPending}
                                        onClick={() => reorderMutation.mutate(movedOrder(ids, index, 1))}
                                    />
                                </Space>
                            ) : (
                                <Typography.Text type="secondary">—</Typography.Text>
                            ),
                    },
                    {
                        title: 'Actions',
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
