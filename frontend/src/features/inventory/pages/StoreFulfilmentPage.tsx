import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, Form, Input, InputNumber, Modal, Select, Space, Table, Tag, Typography, message } from 'antd';
import { useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import {
    listFulfilmentQueue,
    releaseReservation,
    repointReservation,
    reserveForLine,
    sendLineToProduction,
} from '@/features/inventory/api';
import {
    fulfilmentStateLabel,
    fulfilmentStateTone,
    holdSentence,
    repointTargets,
    reservePrefill,
    sendToProductionPrefill,
} from '@/features/inventory/fulfilment';
import type { FulfilmentQueueRow, FulfilmentState } from '@/features/inventory/types';
// The 422 IS the answer — the server refuses on figures recomputed under a
// lock, and its sentence carries the real number. Imported rather than
// re-implemented: cross-feature imports are precedented (StoreIssueQueuePage
// reaches into Production for its shift list) and a second copy of this would
// drift.
import { apiRefusalMessage } from '@/features/material-flow/api';
import { formatQuantity } from '@/features/material-flow/words';
import { itemLabel } from '@/lib/itemLabel';

/**
 * THE STORE FULFILMENT QUEUE — every order line waiting on stock, what is
 * held against it, and the four things the store can do about it.
 *
 * NOTHING ON THIS SCREEN MOVES STOCK (invariant 1). Reserving, releasing and
 * re-pointing change who stock is spoken for and nothing else; dispatch is
 * still the Delivery flow, unchanged and ungated (Q27), which simply SPENDS
 * the hold on the way past. And nothing here creates, starts or cancels a
 * batch (invariant 2): sending a line to production writes a piece of paper.
 *
 * THE SERVER DECIDES EVERYTHING THIS PAGE RENDERS. `fulfilment_state` and
 * `can{}` arrive on the row from the same service the writes refuse in, and
 * the ORDER of the rows — over-reserved first, across the whole queue rather
 * than the page in front of the reader (S8) — is the server's too. So no
 * column carries a `sorter`: one that did would sort 25 rows and quietly
 * defeat the thing it looks like it is implementing.
 *
 * THE DEFAULT VIEW HIDES COVERED LINES (S16). A fully allocated line needs
 * nothing from the store, and a queue whose majority needs nothing stops being
 * read. They are one filter choice away, never gone.
 */

/** The filter's choices — the server's five states, plus its own default. */
const STATE_OPTIONS: { value: FulfilmentState | ''; label: string }[] = [
    // The empty value is not "everything": with no state the server hides
    // fully_allocated. Named as what it does, so nobody reads the queue as a
    // complete list of lines.
    { value: '', label: 'Needs the store' },
    { value: 'untouched', label: fulfilmentStateLabel('untouched') },
    { value: 'partially_allocated', label: fulfilmentStateLabel('partially_allocated') },
    { value: 'awaiting_production', label: fulfilmentStateLabel('awaiting_production') },
    { value: 'over_reserved', label: fulfilmentStateLabel('over_reserved') },
    { value: 'fully_allocated', label: fulfilmentStateLabel('fully_allocated') },
];

const numeric = { fontVariantNumeric: 'tabular-nums' } as const;
const caption = { fontSize: 12, display: 'block' } as const;

/** Every hold on this queue row, oldest first, as the server ordered them. */
function Holds({ row, onRelease, onRepoint }: {
    row: FulfilmentQueueRow;
    onRelease: (row: FulfilmentQueueRow, reservationId: number) => void;
    onRepoint: (row: FulfilmentQueueRow, reservationId: number) => void;
}) {
    if (row.holds.length === 0) return <Typography.Text type="secondary">—</Typography.Text>;

    return (
        <Space direction="vertical" size={2}>
            {row.holds.map((hold) => (
                <Space key={hold.reservation_id} size={6} wrap>
                    <span style={numeric}>{formatQuantity(hold.quantity)}</span>
                    <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                        {holdSentence(hold)}
                    </Typography.Text>
                    {/* Only when a delivery has already spent part of it —
                        otherwise the number reads as a second hold. */}
                    {Number(hold.consumed_quantity) > 0 && (
                        <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                            ({formatQuantity(hold.consumed_quantity)} delivered)
                        </Typography.Text>
                    )}
                    {row.can.release && (
                        <Button size="small" onClick={() => onRelease(row, hold.reservation_id)}>
                            Release
                        </Button>
                    )}
                    {row.can.repoint && (
                        <Button size="small" onClick={() => onRepoint(row, hold.reservation_id)}>
                            Re-point
                        </Button>
                    )}
                </Space>
            ))}
        </Space>
    );
}

export default function StoreFulfilmentPage() {
    const queryClient = useQueryClient();

    /*
     * THE VIEW IS THE URL — the state filter and the page, so a pasted link is
     * the same queue. The same rule the three Sales lists follow
     * (useSalesListParams); kept local here because this page has one filter
     * and no drawer, so the shared hook's document-kind machinery has nothing
     * to do.
     */
    const [searchParams, setSearchParams] = useSearchParams();
    const state = (searchParams.get('state') ?? '') as FulfilmentState | '';
    const page = Number(searchParams.get('page') ?? 1) || 1;
    const perPage = Number(searchParams.get('per_page') ?? 25) || 25;

    const writeParams = (next: { state?: FulfilmentState | ''; page?: number; per_page?: number }) => {
        const params = new URLSearchParams();
        const nextState = next.state ?? state;
        const nextPage = next.page ?? page;
        const nextPerPage = next.per_page ?? perPage;
        if (nextState !== '') params.set('state', nextState);
        if (nextPage !== 1) params.set('page', String(nextPage));
        if (nextPerPage !== 25) params.set('per_page', String(nextPerPage));
        setSearchParams(params, { replace: true });
    };

    const queueQuery = useQuery({
        queryKey: ['inventory', 'fulfilment', 'queue', { state, page, perPage }],
        queryFn: () => listFulfilmentQueue({ state: state === '' ? undefined : state, page, per_page: perPage }),
        placeholderData: (previous) => previous,
    });

    /*
     * THE RE-POINT PICKER'S OWN READ, AND WHY IT IS TWO READS.
     *
     * The queue on screen is one page of 25 and the target line is very often
     * not on it, so the modal asks wide rather than picking from whatever
     * happens to be loaded.
     *
     * TWO CALLS BECAUSE ONE CANNOT ASK FOR EVERYTHING. `state` is a single
     * value on the server (Rule::in(STATES)) and its ABSENCE is not "all" —
     * with no state the queue hides fully_allocated lines (S16). A covered
     * line is a perfectly legal place to move a hold to, so asking once would
     * quietly withhold exactly the targets a store re-points onto most: the
     * order that is already covered and about to go out. The default read
     * plus a named `fully_allocated` read is the whole set.
     */
    const [repointing, setRepointing] = useState<{ row: FulfilmentQueueRow; reservationId: number } | null>(null);
    const targetsQuery = useQuery({
        queryKey: ['inventory', 'fulfilment', 'queue', 'repoint-targets'],
        queryFn: async () => {
            const [needsStore, covered] = await Promise.all([
                listFulfilmentQueue({ per_page: 200 }),
                listFulfilmentQueue({ state: 'fully_allocated', per_page: 200 }),
            ]);

            return [...needsStore.data, ...covered.data];
        },
        enabled: repointing !== null,
    });

    const [reserving, setReserving] = useState<FulfilmentQueueRow | null>(null);
    const [reserveQuantity, setReserveQuantity] = useState<number | null>(null);
    const [sending, setSending] = useState<FulfilmentQueueRow | null>(null);
    const [sendQuantity, setSendQuantity] = useState<number | null>(null);
    const [releasing, setReleasing] = useState<{ row: FulfilmentQueueRow; reservationId: number } | null>(null);
    const [reason, setReason] = useState('');
    const [targetLineId, setTargetLineId] = useState<number | null>(null);
    const [repointQuantity, setRepointQuantity] = useState<number | null>(null);

    /*
     * ONE ACT, FOUR SCREENS. A hold taken here changes the queue, the planning
     * ETA behind it, the floor's worklist and the Sales list's "Ready for
     * dispatch" badge — so every action invalidates all four. Refreshing only
     * this page is what lets two screens tell two stories about the same
     * order, which is the failure the server-computed `can{}` was designed to
     * prevent in the first place.
     */
    const refresh = () => {
        queryClient.invalidateQueries({ queryKey: ['inventory', 'fulfilment'] });
        queryClient.invalidateQueries({ queryKey: ['production', 'requests'] });
        queryClient.invalidateQueries({ queryKey: ['sales', 'sales-orders'] });
    };

    const closeAll = () => {
        setReserving(null);
        setSending(null);
        setReleasing(null);
        setRepointing(null);
        setReason('');
        setTargetLineId(null);
        setReserveQuantity(null);
        setSendQuantity(null);
        setRepointQuantity(null);
    };

    const refuse = (fallback: string) => (error: unknown) => message.error(apiRefusalMessage(error, fallback));

    const reserveMutation = useMutation({
        mutationFn: () => reserveForLine((reserving as FulfilmentQueueRow).line_id, reserveQuantity as number),
        onSuccess: () => {
            message.success('Held. The stock has not moved — a delivery will spend the hold.');
            refresh();
            closeAll();
        },
        onError: refuse('The hold was refused.'),
    });

    const sendMutation = useMutation({
        mutationFn: () => sendLineToProduction((sending as FulfilmentQueueRow).line_id, sendQuantity as number),
        onSuccess: (request) => {
            // The server caps the ask at the line's real shortfall (S14), so
            // the confirmed figure is read back off the request rather than
            // echoed from the box.
            message.success(`${request.request_number} raised for ${formatQuantity(request.quantity)}.`);
            refresh();
            closeAll();
        },
        onError: refuse('The request was refused.'),
    });

    const releaseMutation = useMutation({
        mutationFn: () => releaseReservation((releasing as { reservationId: number }).reservationId, reason),
        onSuccess: () => {
            message.success('Hold given up. The stock stays where it is.');
            refresh();
            closeAll();
        },
        onError: refuse('The release was refused.'),
    });

    const repointMutation = useMutation({
        mutationFn: () =>
            repointReservation((repointing as { reservationId: number }).reservationId, {
                sales_order_line_id: targetLineId as number,
                quantity: repointQuantity as number,
                reason,
            }),
        onSuccess: () => {
            message.success('Hold moved.');
            refresh();
            closeAll();
        },
        onError: refuse('The re-point was refused.'),
    });

    const rows = queueQuery.data?.data ?? [];
    const targets = repointing ? repointTargets(targetsQuery.data ?? [], repointing.row) : [];

    return (
        <>
            <Space style={{ marginBottom: 16, justifyContent: 'space-between', width: '100%' }}>
                <Typography.Title level={3} style={{ margin: 0 }}>Store Fulfilment</Typography.Title>
                <Select<FulfilmentState | ''>
                    value={state}
                    style={{ width: 220 }}
                    options={STATE_OPTIONS}
                    onChange={(next) => writeParams({ state: next, page: 1 })}
                />
            </Space>

            <Table<FulfilmentQueueRow>
                scroll={{ x: 'max-content' }}
                rowKey="line_id"
                loading={queueQuery.isLoading}
                dataSource={rows}
                locale={{ emptyText: queueQuery.isError ? 'The queue could not be read.' : 'Nothing waiting on the store.' }}
                pagination={
                    queueQuery.data?.meta
                        ? {
                              current: queueQuery.data.meta.current_page,
                              pageSize: queueQuery.data.meta.per_page,
                              total: queueQuery.data.meta.total,
                              showSizeChanger: true,
                              pageSizeOptions: [25, 50, 100, 200],
                              showTotal: (total) => `${total} line${total === 1 ? '' : 's'}`,
                              onChange: (nextPage, nextSize) => writeParams({ page: nextPage, per_page: nextSize }),
                          }
                        : false
                }
                columns={[
                    {
                        title: 'Order',
                        render: (_, row) => (
                            <Space direction="vertical" size={0}>
                                <strong>SO-{row.sales_order_id}</strong>
                                <Typography.Text type="secondary" style={caption}>
                                    {row.customer?.name ?? '—'}
                                </Typography.Text>
                            </Space>
                        ),
                    },
                    { title: 'Item', render: (_, row) => (row.item ? itemLabel(row.item) : '—') },
                    {
                        title: 'State',
                        render: (_, row) => (
                            <Tag color={fulfilmentStateTone(row.fulfilment_state)}>
                                {fulfilmentStateLabel(row.fulfilment_state)}
                            </Tag>
                        ),
                    },
                    {
                        title: 'Ordered / delivered',
                        align: 'right',
                        render: (_, row) => (
                            <span style={numeric}>
                                {formatQuantity(row.ordered)} / {formatQuantity(row.delivered)}
                            </span>
                        ),
                    },
                    { title: 'Held', align: 'right', render: (_, row) => <span style={numeric}>{formatQuantity(row.reserved)}</span> },
                    { title: 'Short', align: 'right', render: (_, row) => <span style={numeric}>{formatQuantity(row.shortfall)}</span> },
                    { title: 'Free', align: 'right', render: (_, row) => <span style={numeric}>{formatQuantity(row.free)}</span> },
                    {
                        // S8: an over-promise is PRINTED, never hidden — the
                        // figure in red is the whole reason the row sorted to
                        // the top of the queue, and a zero is left as a dash so
                        // the column reads as an exception list.
                        title: 'Promised twice',
                        align: 'right',
                        render: (_, row) =>
                            Number(row.over_reserved) > 0 ? (
                                <Typography.Text strong style={{ ...numeric, color: '#cf1322' }}>
                                    {formatQuantity(row.over_reserved)}
                                </Typography.Text>
                            ) : (
                                <Typography.Text type="secondary">—</Typography.Text>
                            ),
                    },
                    {
                        title: 'Holds',
                        render: (_, row) => (
                            <Holds
                                row={row}
                                onRelease={(target, reservationId) => {
                                    closeAll();
                                    setReleasing({ row: target, reservationId });
                                }}
                                onRepoint={(target, reservationId) => {
                                    closeAll();
                                    setRepointing({ row: target, reservationId });
                                    setRepointQuantity(null);
                                }}
                            />
                        ),
                    },
                    {
                        title: 'With production',
                        render: (_, row) =>
                            row.request === null ? (
                                <Typography.Text type="secondary">—</Typography.Text>
                            ) : (
                                <Space direction="vertical" size={0}>
                                    <span style={numeric}>{formatQuantity(row.request.quantity)}</span>
                                    <Typography.Text type="secondary" style={caption}>
                                        #{row.request.priority} · {row.request.status.replace('_', ' ')}
                                    </Typography.Text>
                                </Space>
                            ),
                    },
                    {
                        title: 'Actions',
                        render: (_, row) => (
                            <Space>
                                {/* Both buttons read the server's own
                                    predicate. The pair the store's screen turns
                                    on: free = 0 with a shortfall means Reserve
                                    is off and Send to production is on —
                                    there is nothing to hold, so the answer is
                                    to make it. */}
                                {row.can.reserve && (
                                    <Button
                                        size="small"
                                        type="primary"
                                        onClick={() => {
                                            closeAll();
                                            setReserving(row);
                                            setReserveQuantity(reservePrefill(row));
                                        }}
                                    >
                                        Reserve
                                    </Button>
                                )}
                                {row.can.send_to_production && (
                                    <Button
                                        size="small"
                                        onClick={() => {
                                            closeAll();
                                            setSending(row);
                                            setSendQuantity(sendToProductionPrefill(row));
                                        }}
                                    >
                                        Send to production
                                    </Button>
                                )}
                            </Space>
                        ),
                    },
                ]}
            />

            <Modal
                maskClosable={false}
                title={reserving ? `Reserve — SO-${reserving.sales_order_id}` : 'Reserve'}
                open={reserving !== null}
                onCancel={closeAll}
                onOk={() => reserveMutation.mutate()}
                okButtonProps={{ disabled: reserveQuantity === null || reserveQuantity <= 0 }}
                confirmLoading={reserveMutation.isPending}
                destroyOnHidden
            >
                <Form layout="vertical">
                    <Form.Item label={`Quantity (free ${formatQuantity(reserving?.free)}, short ${formatQuantity(reserving?.shortfall)})`}>
                        <InputNumber
                            style={{ width: '100%' }}
                            min={0}
                            value={reserveQuantity}
                            onChange={setReserveQuantity}
                            autoFocus
                        />
                    </Form.Item>
                </Form>
            </Modal>

            <Modal
                maskClosable={false}
                title={sending ? `Send to production — SO-${sending.sales_order_id}` : 'Send to production'}
                open={sending !== null}
                onCancel={closeAll}
                onOk={() => sendMutation.mutate()}
                okButtonProps={{ disabled: sendQuantity === null || sendQuantity <= 0 }}
                confirmLoading={sendMutation.isPending}
                destroyOnHidden
            >
                <Form layout="vertical">
                    <Form.Item label={`Quantity (short ${formatQuantity(sending?.shortfall)})`}>
                        <InputNumber
                            style={{ width: '100%' }}
                            min={0}
                            value={sendQuantity}
                            onChange={setSendQuantity}
                            autoFocus
                        />
                    </Form.Item>
                </Form>
            </Modal>

            <Modal
                maskClosable={false}
                title="Release hold"
                open={releasing !== null}
                onCancel={closeAll}
                onOk={() => releaseMutation.mutate()}
                okButtonProps={{ danger: true, disabled: reason.trim().length < 3 }}
                confirmLoading={releaseMutation.isPending}
                destroyOnHidden
            >
                <Form layout="vertical">
                    {/* The reason is kept on the row for good — a hold is never
                        deleted and never edited, only given up. */}
                    <Form.Item label="Reason">
                        <Input value={reason} onChange={(event) => setReason(event.target.value)} autoFocus />
                    </Form.Item>
                </Form>
            </Modal>

            <Modal
                maskClosable={false}
                title="Re-point hold"
                open={repointing !== null}
                onCancel={closeAll}
                onOk={() => repointMutation.mutate()}
                okButtonProps={{
                    disabled: targetLineId === null || repointQuantity === null || repointQuantity <= 0 || reason.trim().length < 3,
                }}
                confirmLoading={repointMutation.isPending}
                destroyOnHidden
                width={620}
            >
                <Form layout="vertical">
                    <Form.Item label="Move it to">
                        {/* SAME PRODUCT, DIFFERENT LINE — the two refusals
                            repoint() carries (repointItemMismatch,
                            cannotRepointToSameLine). Offering a choice the
                            server will reject is worse than offering none. */}
                        <Select<number>
                            style={{ width: '100%' }}
                            value={targetLineId}
                            loading={targetsQuery.isLoading}
                            showSearch
                            optionFilterProp="label"
                            placeholder={targets.length === 0 ? 'No other open line wants this product' : 'Order line'}
                            options={targets.map((target) => ({
                                value: target.line_id,
                                label: `SO-${target.sales_order_id} · ${target.customer?.name ?? '—'} · short ${formatQuantity(target.shortfall)}`,
                            }))}
                            onChange={setTargetLineId}
                        />
                    </Form.Item>
                    <Form.Item label="Quantity">
                        <InputNumber style={{ width: '100%' }} min={0} value={repointQuantity} onChange={setRepointQuantity} />
                    </Form.Item>
                    <Form.Item label="Reason">
                        <Input value={reason} onChange={(event) => setReason(event.target.value)} />
                    </Form.Item>
                </Form>
            </Modal>
        </>
    );
}
