import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Button, Card, Col, DatePicker, Descriptions, Drawer, Empty, Input, InputNumber, Modal, Row, Select, Space, Table, Tag, Tooltip, Typography, message } from 'antd';
import type { Dayjs } from 'dayjs';
import { useState } from 'react';
import { listShifts, listWorkCenters, machineLabel } from '@/features/production/api';
import { itemLabel } from '@/lib/itemLabel';
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
import type { MaterialRequest, MaterialRequestFilters, StoreIssue } from '../types';
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
    type MaterialRequestStatus,
} from '../words';

/** "Still to issue" is these two statuses, as the backend spells the filter. */
const OPEN_STATUSES: MaterialRequestStatus[] = ['submitted', 'partially_issued'];

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
export default function StoreIssueQueuePage() {
    const queryClient = useQueryClient();
    /**
     * The queue's default view is "still to issue" — which the backend
     * expresses as a status LIST (submitted + partially_issued), not as a
     * status of its own. Kept as a list here so the server does the
     * narrowing, in SQL, over the whole queue rather than over one page.
     */
    const [filters, setFilters] = useState<MaterialRequestFilters>({ status: [...OPEN_STATUSES] });
    const [dates, setDates] = useState<[Dayjs | null, Dayjs | null] | null>(null);
    const [openRequestId, setOpenRequestId] = useState<number | null>(null);
    const [issueQuantities, setIssueQuantities] = useState<Record<number, number | null>>({});
    const [receivedBy, setReceivedBy] = useState<number | null>(null);
    const [returningIssue, setReturningIssue] = useState<StoreIssue | null>(null);
    const [cancelOpen, setCancelOpen] = useState(false);
    const [cancelReason, setCancelReason] = useState('');

    const queueQuery = useQuery({
        queryKey: ['material-flow', 'queue', filters],
        queryFn: () => listMaterialRequests(filters),
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
        queryFn: () => listStoreIssues({ material_request_id: openRequestId as number }),
        enabled: openRequestId !== null,
        retry: false,
    });
    const shiftsQuery = useQuery({ queryKey: ['production', 'shifts', 'active'], queryFn: () => listShifts(true) });
    const machinesQuery = useQuery({ queryKey: ['production', 'work-centers', 'active'], queryFn: () => listWorkCenters(true) });
    const materialsQuery = useQuery({ queryKey: ['material-flow', 'materials'], queryFn: listRequestableMaterials });

    const refresh = () => {
        queryClient.invalidateQueries({ queryKey: ['material-flow', 'queue'] });
        queryClient.invalidateQueries({ queryKey: ['material-flow', 'request'] });
        queryClient.invalidateQueries({ queryKey: ['material-flow', 'issues'] });
    };

    const request = requestQuery.data;
    const issues = request?.issues ?? issuesQuery.data?.data ?? [];

    const issueMutation = useMutation({
        mutationFn: () =>
            issueToProduction({
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
            message.success(`Issued to production. The material now stands in ${LOCATION_LABEL.production_wip} — it is not consumed.`);
            setIssueQuantities({});
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

    const setFilter = (patch: MaterialRequestFilters) => setFilters((current) => ({ ...current, ...patch }));

    return (
        <>
            <Typography.Title level={3} style={{ marginTop: 0 }}>
                Store Issue Queue
            </Typography.Title>

            <Alert type="info" showIcon style={{ marginBottom: 16 }} message={ISSUE_IS_NOT_CONSUMPTION} />

            <Card size="small" style={{ marginBottom: 16 }}>
                <Row gutter={[8, 8]}>
                    <Col xs={24} sm={8} md={5}>
                        <Select
                            style={{ width: '100%' }}
                            value={Array.isArray(filters.status) ? 'open' : (filters.status ?? 'open')}
                            onChange={(value) =>
                                setFilter({ status: value === 'open' ? [...OPEN_STATUSES] : (value as MaterialRequestStatus) })
                            }
                            options={[
                                { value: 'open', label: 'Still to issue' },
                                { value: 'submitted', label: REQUEST_STATUS_LABEL.submitted },
                                { value: 'partially_issued', label: REQUEST_STATUS_LABEL.partially_issued },
                                { value: 'issued', label: REQUEST_STATUS_LABEL.issued },
                                { value: 'cancelled', label: REQUEST_STATUS_LABEL.cancelled },
                            ]}
                        />
                    </Col>
                    <Col xs={24} sm={16} md={7}>
                        <DatePicker.RangePicker
                            style={{ width: '100%' }}
                            value={dates}
                            onChange={(range) => {
                                setDates(range as [Dayjs | null, Dayjs | null] | null);
                                setFilter({
                                    from: range?.[0]?.format('YYYY-MM-DD'),
                                    to: range?.[1]?.format('YYYY-MM-DD'),
                                });
                            }}
                        />
                    </Col>
                    <Col xs={24} sm={8} md={4}>
                        <Select
                            allowClear
                            style={{ width: '100%' }}
                            placeholder="Shift"
                            value={filters.shift_id}
                            onChange={(value) => setFilter({ shift_id: value })}
                            options={(shiftsQuery.data?.data ?? []).map((shift) => ({ value: shift.id, label: shift.name }))}
                        />
                    </Col>
                    <Col xs={24} sm={8} md={4}>
                        <Select
                            allowClear
                            style={{ width: '100%' }}
                            placeholder="Machine / area"
                            value={filters.work_center_id}
                            onChange={(value) => setFilter({ work_center_id: value })}
                            options={(machinesQuery.data?.data ?? []).map((machine) => ({
                                value: machine.id,
                                label: machineLabel(machine),
                            }))}
                        />
                    </Col>
                    <Col xs={24} sm={8} md={4}>
                        <Select
                            allowClear
                            showSearch
                            optionFilterProp="label"
                            style={{ width: '100%' }}
                            placeholder="Material"
                            value={filters.item_id}
                            onChange={(value) => setFilter({ item_id: value })}
                            options={(materialsQuery.data ?? []).map((material) => ({
                                value: material.id,
                                label: itemLabel(material),
                            }))}
                        />
                    </Col>
                </Row>
                <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                    Every filter is applied by the server, on the whole queue — not to the page you are looking at.
                </Typography.Text>
            </Card>

            <Table<MaterialRequest>
                rowKey="id"
                loading={queueQuery.isLoading}
                dataSource={queueQuery.data?.data}
                pagination={false}
                scroll={{ x: 'max-content' }}
                locale={{ emptyText: <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="Nothing waiting on the store." /> }}
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
                                    {issue.is_open ? (
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
