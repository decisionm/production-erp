import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Button, Card, Col, DatePicker, Descriptions, Drawer, Empty, Input, InputNumber, Modal, Row, Select, Space, Table, Tag, Tooltip, Typography, message } from 'antd';
import dayjs from 'dayjs';
import { useEffect, useMemo, useState } from 'react';
import { listShifts, listWorkCenters, machineLabel } from '@/features/production/api';
import { itemLabel } from '@/lib/itemLabel';
import { ListEmpty, ListReadAlert } from '@/lib/ListEmpty';
import { MAX_PER_PAGE, narrowingKeys } from '@/lib/listParams';
import { TABLE_STICKY, serverPagination } from '@/lib/tableProps';
import { useListParams } from '@/lib/useListParams';
import {
    apiRefusalMessage,
    cancelMaterialRequest,
    getMaterialRequest,
    issueToProduction,
    listMaterialRequests,
    listRequestableMaterials,
    listStoreIssues,
} from '../api';
import HandoverPanel from '../components/HandoverPanel';
import PersonSelect from '../components/PersonSelect';
import RequestLinesTable from '../components/RequestLinesTable';
import ReturnToStoreModal from '../components/ReturnToStoreModal';
import {
    QUEUE_LIST_SPEC,
    type QueueListParams,
    noMatchLine,
    pageRangeLine,
    queueQueryKey,
    queueServerFilters,
    queueStatusChoice,
} from '../lists';
import type { MaterialRequest, StoreIssue } from '../types';
import {
    ISSUE_IS_NOT_CONSUMPTION,
    LOCATION_LABEL,
    OVER_ISSUE_IS_ORDINARY,
    REFUSAL_MESSAGE,
    REQUEST_STATUS_HELP,
    REQUEST_STATUS_LABEL,
    REQUEST_STATUS_TONE,
    STATE_COLUMN_LABEL,
    TRANSITION_HELP,
    TRANSITION_LABEL,
    formatQuantity,
    permitsFractions,
    queueEmptyText,
    type QueueStatusChoice,
} from '../words';

/**
 * THE STORE'S QUEUE — what production has asked for, and what the store has
 * actually handed over.
 *
 * The one thing this screen must never let happen is a storekeeper reading
 * "issued" as "used up". Issuing moves material from the Raw Material Store
 * into Production/WIP (DEC-20260817-001); it is still stock, it is still the
 * factory's, and if the shift does not use it, it comes back. Consumption is
 * a different event entirely, booked when a batch is completed, on a
 * different screen, by a different person. So the queue, the handover panel
 * and the return dialog all name the states in full, in the words the whole
 * flow shares.
 *
 * Fulfilment is partial by default: the store issues what it has, the server
 * recomputes what is still to issue, and the request stays open until it is
 * either fully issued or cancelled. No cadence is assumed — a request is not
 * a daily ritual, and nothing here expects one issue per day.
 */
/**
 * A fresh idempotency key for one handover.
 *
 * The lifecycle is the whole point, and it is deliberately asymmetric:
 *   · a FAILED submit keeps the key, so the storekeeper's retry — after a
 *     timeout, a flaky connection, a double-tap — replays the original issue
 *     instead of handing the material over twice;
 *   · a SUCCESSFUL submit rotates it, because the next part-issue against the
 *     same request is a genuinely new handover and must not replay the last.
 *
 * Same shape as the goods receipt's key (GoodsReceiptsPage.newReceiptKey).
 */
function newIssueKey(): string {
    return typeof crypto.randomUUID === 'function'
        ? crypto.randomUUID()
        : `si-${Date.now()}-${Math.random().toString(36).slice(2)}`;
}

/**
 * `embedded` — rendered as a tab of Store ↔ Production rather than as a page
 * of its own, so the shell owns the heading and the banner. Nothing else
 * about the screen changes: same reads, same filters, same write paths.
 */
/**
 * Has this handover material still out on the floor?
 *
 * NOT `issue.is_open`, and the difference is the whole point. `is_open` is
 * Issued | PartiallyReturned, so a COMPLETED handover with kilograms still
 * outstanding failed that test and lost its Return button — while the server
 * has never gated returnUnused on status, and ProductionReturnService keeps
 * completed issues standing deliberately. The material was reachable only
 * from the Returns tab, and any attempt to tidy the two return doors into one
 * would have stranded it completely.
 *
 * The owner settled it on 31-Aug-2026 (DEC-20260831-012): a return must name
 * its store issue for open, partially returned AND completed issues, while
 * returnable quantity remains. So the door opens on exactly that condition — what is outstanding,
 * not what the paperwork is called.
 */
function hasMaterialOutstanding(issue: StoreIssue): boolean {
    return (issue.lines ?? []).some((line) => Number(line.quantity_outstanding ?? 0) > 0);
}

export default function StoreIssueQueuePage({ embedded = false }: { embedded?: boolean }) {
    const queryClient = useQueryClient();
    /**
     * The search, the filters, the page and the page size ARE the URL —
     * and the URL carries only what this list manages, so the workspace's
     * `?tab=issues` survives every page turn (useListParams).
     *
     * The queue's default view is "still to issue" — which the backend
     * expresses as a status LIST (submitted + partially_issued), not as a
     * status of its own. That is what an absent `status` means here; the
     * mapping lives in lists.ts and the server does the narrowing, in SQL,
     * over the whole queue rather than over one page.
     */
    const { params, setParams, setPage, reset } = useListParams<QueueListParams>(QUEUE_LIST_SPEC);
    const filters = useMemo(() => queueServerFilters(params), [params]);
    const statusChoice = queueStatusChoice(params);
    const term = params.q;
    const filtersActive = narrowingKeys(params).length > 0;
    // What is typed; the URL (and the server) hear it on Enter or the button.
    const [qDraft, setQDraft] = useState(params.q ?? '');
    useEffect(() => {
        setQDraft(params.q ?? '');
    }, [params.q]);
    const [openRequestId, setOpenRequestId] = useState<number | null>(null);
    const [issueQuantities, setIssueQuantities] = useState<Record<number, number | null>>({});
    const [receivedBy, setReceivedBy] = useState<number | null>(null);
    const [returningIssue, setReturningIssue] = useState<StoreIssue | null>(null);
    const [cancelOpen, setCancelOpen] = useState(false);
    const [cancelReason, setCancelReason] = useState('');

    const queueQuery = useQuery({
        queryKey: queueQueryKey(filters),
        queryFn: () => listMaterialRequests(filters),
        // Turning a page keeps the last page on screen until the next one
        // lands; a refetch that fails then has rows in front of it, which
        // is why ListReadAlert sits above the table.
        placeholderData: (previous) => previous,
    });
    const requestQuery = useQuery({
        queryKey: ['material-flow', 'request', openRequestId],
        queryFn: () => getMaterialRequest(openRequestId as number),
        enabled: openRequestId !== null,
    });
    /**
     * The handovers made against the open request. Asked for separately
     * rather than assumed to ride along on the request: the request endpoint
     * carries them where it can, and where it cannot this read still shows
     * the store what it has already handed over. One retry only — an
     * endpoint that is not there yet should leave an empty list and a plain
     * line on screen, not a spinner that never settles.
     */
    const issuesQuery = useQuery({
        queryKey: ['material-flow', 'issues', openRequestId],
        // No pager in the drawer, so the ceiling is asked for outright: a
        // request's handovers must never be cut off at the server's default.
        queryFn: () => listStoreIssues({ material_request_id: openRequestId as number, per_page: MAX_PER_PAGE }),
        enabled: openRequestId !== null,
        retry: false,
    });
    const shiftsQuery = useQuery({ queryKey: ['production', 'shifts', 'active'], queryFn: () => listShifts(true) });
    const machinesQuery = useQuery({ queryKey: ['production', 'work-centers', 'active'], queryFn: () => listWorkCenters(true) });
    const materialsQuery = useQuery({ queryKey: ['material-flow', 'materials'], queryFn: listRequestableMaterials });

    /**
     * WIDENED TO THE WHOLE `material-flow` PREFIX WHEN THIS SCREEN BECAME A
     * TAB, and it is not cosmetic. rc-tabs keeps a visited pane MOUNTED while
     * it is hidden and `refetchOnWindowFocus` is off, so the Returns tab does
     * not re-read on its way back to the front — it shows whatever it last
     * fetched. Three specific keys left it showing pre-issue figures.
     *
     * The failure that makes this load-bearing rather than tidy: completing a
     * handover here CLOSES that issue, which moves quantity out of the
     * Returns tab's "Held by a store issue" column into "Free to return" AND
     * lifts ProductionReturnService's refusal of an unattributed return
     * standing over an open issue. Without this invalidation the storekeeper
     * reads a disabled input and a refusal that no longer applies, and a
     * retry succeeds with no explanation for why it did.
     *
     * The cost, stated rather than left to be discovered: this also
     * invalidates ['material-flow', 'materials'] — the requestable-materials
     * master read — on every issue, handover, bag scan and cancel. It is a
     * small, rarely-changing list, and a stale refusal is the worse trade.
     */
    const refresh = () => {
        queryClient.invalidateQueries({ queryKey: ['material-flow'] });
    };

    const request = requestQuery.data;
    const issues = request?.issues ?? issuesQuery.data?.data ?? [];

    // Re-minted whenever a different request is opened: two requests are two
    // handovers and must never share a key.
    const [issueKey, setIssueKey] = useState(newIssueKey);
    useEffect(() => {
        setIssueKey(newIssueKey());
    }, [openRequestId]);

    const issueMutation = useMutation({
        mutationFn: () =>
            issueToProduction({
                issue_key: issueKey,
                material_request_id: (request as MaterialRequest).id,
                received_by: receivedBy,
                lines: Object.entries(issueQuantities)
                    .filter(([, quantity]) => typeof quantity === 'number' && quantity > 0)
                    .map(([lineId, quantity]) => {
                        const line = (request as MaterialRequest).lines.find((row) => row.id === Number(lineId));

                        return {
                            material_request_line_id: Number(lineId),
                            // The item and the ask travel with the line, so the
                            // server's remaining arithmetic is done against the
                            // request's own snapshot rather than a figure this
                            // screen kept in step by hand.
                            item_id: line?.item_id as number,
                            quantity: quantity as number,
                            quantity_requested: line?.quantity ?? null,
                            uom: line?.uom ?? null,
                        };
                    }),
            }),
        onSuccess: () => {
            message.success(
                `Issued to production. The material now stands in ${LOCATION_LABEL.production_wip} — it is not consumed. `
                + 'A fully issued request leaves this queue; find it under "Fully issued".',
            );
            setIssueQuantities({});
            // Rotate ONLY here. An error deliberately leaves the key alone so
            // the retry is a replay rather than a second handover.
            setIssueKey(newIssueKey());
            refresh();
        },
        onError: (error) => message.error(apiRefusalMessage(error, REFUSAL_MESSAGE.issue_refused)),
    });

    const startIssue = () => {
        const entered = Object.values(issueQuantities).filter((quantity) => typeof quantity === 'number' && quantity > 0);
        if (entered.length === 0) {
            message.error(REFUSAL_MESSAGE.quantity_not_positive);
            return;
        }
        issueMutation.mutate();
    };

    /**
     * The store may withdraw a request too, with a reason — it is the side
     * that knows it cannot fulfil one. Cancelling never reverses a handover
     * already made: material standing in Production/WIP comes back through a
     * return, which is a stock movement, not a change of paperwork.
     */
    const cancelRequestMutation = useMutation({
        mutationFn: () => cancelMaterialRequest((request as MaterialRequest).id, cancelReason.trim()),
        onSuccess: () => {
            setCancelOpen(false);
            setCancelReason('');
            refresh();
        },
        onError: (error) => message.error(apiRefusalMessage(error, REFUSAL_MESSAGE.request_closed)),
    });

    // What an EMPTY table says is judged on the query's state (ListEmpty),
    // and then on WHY it is empty: a term that matched nothing names the
    // term and offers to drop it; a narrowed view offers its filters back;
    // the bare default keeps the queue's own wording.
    const emptyText = term ? (
        <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description={noMatchLine('requests', term)}>
            <Button size="small" onClick={() => setParams({ q: undefined })}>
                Clear search
            </Button>
        </Empty>
    ) : (
        <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description={queueEmptyText(filters.status)}>
            {filtersActive ? (
                <Button size="small" onClick={reset}>
                    Clear filters
                </Button>
            ) : null}
        </Empty>
    );

    return (
        <>
            {!embedded && (
                <>
                    <Typography.Title level={3} style={{ marginTop: 0 }}>
                        Store Issue Queue
                    </Typography.Title>

                    <Alert type="info" showIcon style={{ marginBottom: 16 }} message={ISSUE_IS_NOT_CONSUMPTION} />
                </>
            )}

            <Card size="small" style={{ marginBottom: 16 }}>
                <Row gutter={[8, 8]}>
                    <Col xs={24} sm={12} md={6}>
                        <Input.Search
                            allowClear
                            placeholder="Request no."
                            value={qDraft}
                            onChange={(event) => setQDraft(event.target.value)}
                            onSearch={(value) => setParams({ q: value.trim() || undefined })}
                        />
                    </Col>
                    <Col xs={24} sm={12} md={4}>
                        <Select<QueueStatusChoice>
                            style={{ width: '100%' }}
                            value={statusChoice}
                            onChange={(value) => setParams({ status: value })}
                            options={[
                                { value: 'open', label: 'Still to issue' },
                                { value: 'all', label: 'All requests (including issued)' },
                                { value: 'submitted', label: REQUEST_STATUS_LABEL.submitted },
                                { value: 'partially_issued', label: REQUEST_STATUS_LABEL.partially_issued },
                                { value: 'issued', label: REQUEST_STATUS_LABEL.issued },
                                { value: 'cancelled', label: REQUEST_STATUS_LABEL.cancelled },
                            ]}
                        />
                    </Col>
                    <Col xs={24} sm={12} md={5}>
                        <DatePicker.RangePicker
                            style={{ width: '100%' }}
                            allowEmpty={[true, true]}
                            value={[params.from ? dayjs(params.from) : null, params.to ? dayjs(params.to) : null]}
                            onChange={(_, dateStrings) =>
                                setParams({ from: dateStrings[0] || undefined, to: dateStrings[1] || undefined })
                            }
                        />
                    </Col>
                    <Col xs={24} sm={8} md={3}>
                        <Select
                            allowClear
                            style={{ width: '100%' }}
                            placeholder="Shift"
                            value={params.shift_id}
                            onChange={(value) => setParams({ shift_id: value ?? undefined })}
                            options={(shiftsQuery.data?.data ?? []).map((shift) => ({ value: shift.id, label: shift.name }))}
                        />
                    </Col>
                    <Col xs={24} sm={8} md={3}>
                        <Select
                            allowClear
                            style={{ width: '100%' }}
                            placeholder="Machine / area"
                            value={params.work_center_id}
                            onChange={(value) => setParams({ work_center_id: value ?? undefined })}
                            options={(machinesQuery.data?.data ?? []).map((machine) => ({
                                value: machine.id,
                                label: machineLabel(machine),
                            }))}
                        />
                    </Col>
                    <Col xs={24} sm={8} md={3}>
                        <Select
                            allowClear
                            showSearch
                            optionFilterProp="label"
                            style={{ width: '100%' }}
                            placeholder="Material"
                            value={params.item_id}
                            onChange={(value) => setParams({ item_id: value ?? undefined })}
                            options={(materialsQuery.data ?? []).map((material) => ({
                                value: material.id,
                                label: itemLabel(material),
                            }))}
                        />
                    </Col>
                </Row>
                <Space style={{ marginTop: 8 }} wrap>
                    <Typography.Text type="secondary">{pageRangeLine(queueQuery.data?.meta, 'requests')}</Typography.Text>
                    {filtersActive ? (
                        <Button size="small" onClick={reset}>
                            Clear
                        </Button>
                    ) : null}
                </Space>
            </Card>

            <ListReadAlert state={queueQuery} entity="the store's queue" />

            <Table<MaterialRequest>
                rowKey="id"
                sticky={TABLE_STICKY}
                scroll={{ x: 'max-content' }}
                loading={queueQuery.isFetching}
                dataSource={queueQuery.data?.data}
                pagination={serverPagination(queueQuery.data?.meta, setPage, 'requests')}
                locale={{ emptyText: <ListEmpty state={queueQuery} entity="the store's queue" empty={emptyText} /> }}
                columns={[
                    { title: 'Request', dataIndex: 'request_number' },
                    {
                        title: 'Status',
                        render: (_, row) => (
                            <Tooltip title={REQUEST_STATUS_HELP[row.status]}>
                                <Tag color={REQUEST_STATUS_TONE[row.status]}>{REQUEST_STATUS_LABEL[row.status]}</Tag>
                            </Tooltip>
                        ),
                    },
                    { title: 'Raised by', render: (_, row) => row.requested_by_name ?? '—' },
                    { title: 'Raised at', render: (_, row) => row.requested_at ?? '—' },
                    { title: 'Shift', render: (_, row) => row.shift_name ?? '—' },
                    {
                        title: 'Machine / area',
                        render: (_, row) =>
                            row.work_center_name ?? <Typography.Text type="secondary">None — common input (FC-01)</Typography.Text>,
                    },
                    {
                        title: 'Materials',
                        render: (_, row) => row.lines.map((line) => itemLabel(line.item)).join(', ') || '—',
                    },
                    {
                        title: 'Actions',
                        render: (_, row) => (
                            <Button
                                size="small"
                                type="primary"
                                onClick={() => {
                                    setOpenRequestId(row.id);
                                    setIssueQuantities({});
                                    setReceivedBy(null);
                                }}
                            >
                                Open
                            </Button>
                        ),
                    },
                ]}
            />

            <Drawer
                open={openRequestId !== null}
                width={960}
                onClose={() => setOpenRequestId(null)}
                title={request ? `Request ${request.request_number}` : 'Request'}
                extra={
                    request?.can.cancel ? (
                        <Button danger onClick={() => setCancelOpen(true)}>
                            {TRANSITION_LABEL.cancel_request}
                        </Button>
                    ) : null
                }
                destroyOnHidden
            >
                {request ? (
                    <>
                        <Descriptions size="small" column={{ xs: 1, sm: 2 }} style={{ marginBottom: 12 }}>
                            <Descriptions.Item label="Status">
                                <Tag color={REQUEST_STATUS_TONE[request.status]}>{REQUEST_STATUS_LABEL[request.status]}</Tag>
                            </Descriptions.Item>
                            <Descriptions.Item label="Raised by">{request.requested_by_name ?? '—'}</Descriptions.Item>
                            <Descriptions.Item label="Shift">{request.shift_name ?? '—'}</Descriptions.Item>
                            <Descriptions.Item label="Machine / area">
                                {request.work_center_name ?? 'None — common input (FC-01)'}
                            </Descriptions.Item>
                            <Descriptions.Item label="Notes">{request.notes ?? '—'}</Descriptions.Item>
                            <Descriptions.Item label="Cancellation reason">
                                {request.cancelled_reason ?? '—'}
                            </Descriptions.Item>
                        </Descriptions>

                        <RequestLinesTable lines={request.lines} />

                        {request.can.issue ? (
                            <Card size="small" title={TRANSITION_LABEL.start_issue} style={{ marginTop: 16 }}>
                                <Typography.Paragraph type="secondary">{TRANSITION_HELP.start_issue}</Typography.Paragraph>
                                <Table
                                    size="small"
                                    rowKey="id"
                                    pagination={false}
                                    dataSource={request.lines}
                                    scroll={{ x: 'max-content' }}
                                    columns={[
                                        { title: 'Material', render: (_, line) => itemLabel(line.item) },
                                        {
                                            title: 'Still to issue',
                                            align: 'right',
                                            render: (_, line) => formatQuantity(line.remaining_quantity, line.uom),
                                        },
                                        {
                                            title: STATE_COLUMN_LABEL.issued_to_production,
                                            align: 'right',
                                            render: (_, line) => formatQuantity(line.issued_quantity, line.uom),
                                        },
                                        {
                                            title: 'Issuing now',
                                            align: 'right',
                                            render: (_, line) => (
                                                <InputNumber
                                                    min={0}
                                                    // A COUNTED MATERIAL CANNOT BE TYPED IN HALVES.
                                                    // The server refuses it either way; this stops
                                                    // the storekeeper writing 12.5 trays in the
                                                    // first place. 26 of the factory's 43 stock
                                                    // items are counted, not weighed.
                                                    precision={permitsFractions(line.uom) ? undefined : 0}
                                                    step={permitsFractions(line.uom) ? undefined : 1}
                                                    style={{ width: 140 }}
                                                    placeholder={line.uom}
                                                    value={issueQuantities[line.id] ?? null}
                                                    onChange={(value) =>
                                                        setIssueQuantities((current) => ({ ...current, [line.id]: value }))
                                                    }
                                                />
                                            ),
                                        },
                                    ]}
                                />
                                <Space direction="vertical" size={8} style={{ width: '100%', marginTop: 12 }}>
                                    <PersonSelect
                                        value={receivedBy}
                                        onChange={setReceivedBy}
                                        placeholder="Received by — the person on the floor taking the material"
                                        style={{ width: 460 }}
                                    />
                                    <Space wrap>
                                        <Button type="primary" loading={issueMutation.isPending} onClick={startIssue}>
                                            {TRANSITION_LABEL.start_issue}
                                        </Button>
                                        <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                                            Issue part of a line or all of it — the store issues what it has, and the rest
                                            stays on the request. {OVER_ISSUE_IS_ORDINARY}
                                        </Typography.Text>
                                    </Space>
                                </Space>
                            </Card>
                        ) : (
                            <Alert
                                type="warning"
                                showIcon
                                style={{ marginTop: 16 }}
                                message={
                                    request.status === 'draft' ? REFUSAL_MESSAGE.request_not_submitted : REFUSAL_MESSAGE.request_closed
                                }
                            />
                        )}

                        <Typography.Title level={5} style={{ marginTop: 24 }}>
                            Handovers against this request
                        </Typography.Title>
                        {issues.length === 0 ? (
                            <Empty
                                image={Empty.PRESENTED_IMAGE_SIMPLE}
                                description="Nothing has been issued yet. The material is still in the Raw Material Store."
                            />
                        ) : (
                            issues.map((issue) => (
                                <div key={issue.id}>
                                    <HandoverPanel issue={issue} onChanged={refresh} />
                                    {hasMaterialOutstanding(issue) ? (
                                        <Space style={{ marginBottom: 16 }}>
                                            <Button onClick={() => setReturningIssue(issue)}>
                                                {TRANSITION_LABEL.return_to_store}
                                            </Button>
                                            <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                                                {TRANSITION_HELP.return_to_store}
                                            </Typography.Text>
                                        </Space>
                                    ) : null}
                                </div>
                            ))
                        )}
                    </>
                ) : (
                    <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="Loading the request…" />
                )}
            </Drawer>

            <Modal
                open={cancelOpen}
                title={`${TRANSITION_LABEL.cancel_request} ${request?.request_number ?? ''}`}
                okText={TRANSITION_LABEL.cancel_request}
                okButtonProps={{ danger: true, loading: cancelRequestMutation.isPending }}
                onCancel={() => setCancelOpen(false)}
                onOk={() => {
                    if (cancelReason.trim() === '') {
                        message.error(REFUSAL_MESSAGE.reason_missing);
                        return;
                    }
                    cancelRequestMutation.mutate();
                }}
                destroyOnHidden
            >
                <Typography.Paragraph type="secondary">
                    Withdrawing the request stops anything further being issued against it. Material already handed over
                    stays in Production/WIP until it is used or returned — cancelling paperwork moves no stock.
                </Typography.Paragraph>
                <Input.TextArea
                    rows={3}
                    value={cancelReason}
                    onChange={(event) => setCancelReason(event.target.value)}
                    placeholder="Why can this request not be fulfilled?"
                />
            </Modal>

            {returningIssue ? (
                <ReturnToStoreModal
                    issue={returningIssue}
                    open
                    onClose={() => setReturningIssue(null)}
                    onChanged={refresh}
                />
            ) : null}
        </>
    );
}
