import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Button, Card, Col, Empty, Input, InputNumber, Modal, Row, Select, Space, Table, Tag, Tooltip, Typography, message } from 'antd';
import { useMemo, useState } from 'react';
import { listShifts, listWorkCenters, machineLabel } from '@/features/production/api';
import { itemLabel } from '@/lib/itemLabel';
import { ListEmpty } from '@/lib/ListEmpty';
import {
    apiRefusalMessage,
    cancelMaterialRequest,
    createMaterialRequest,
    listMaterialRequests,
    listProductionFloorStock,
    listRequestableMaterials,
    submitMaterialRequest,
} from '../api';
import RequestLinesTable from '../components/RequestLinesTable';
import { netAgainstProduction } from '../productionNetting';
import type { CreateMaterialRequestLinePayload, MaterialFlowMaterial, MaterialRequest, ProductionFloorStock } from '../types';
import {
    formatQuantity,
    ISSUE_IS_NOT_CONSUMPTION,
    LOCATION_LABEL,
    machineAppliesToRequest,
    machineFieldDecision,
    REFUSAL_MESSAGE,
    REQUEST_STATUS_HELP,
    REQUEST_STATUS_LABEL,
    REQUEST_STATUS_TONE,
    TRANSITION_HELP,
    TRANSITION_LABEL,
    type MaterialRequestStatus,
} from '../words';

/**
 * PRODUCTION'S SIDE OF THE MATERIAL FLOW — ask the store for material, and
 * then watch what has actually happened to it.
 *
 * The screen is built around one refusal to blur: a request that reads
 * "issued" has had material handed over, and that material is standing in
 * Production/WIP as stock. It has NOT been consumed. Consumption happens
 * once, later, when a batch is completed — so every line shows four
 * separately named quantities (asked for · issued to production, not yet
 * consumed · still to issue · returned to store) rather than one "done"
 * number that would quietly mean whichever of them the reader assumed.
 *
 * The machine/area field follows FC-01 and nothing else: it appears only for
 * a material the SERVER says takes one. Resin never does — the factory has
 * one common loading point, crane-fed and piped to all ten machines
 * (DEC-20260807-006) — and where the backend has not said, this screen names
 * no machine and guesses none.
 */
const STATUS_FILTERS: { value: MaterialRequestStatus | 'all'; label: string }[] = [
    { value: 'all', label: 'All requests' },
    { value: 'draft', label: REQUEST_STATUS_LABEL.draft },
    { value: 'submitted', label: REQUEST_STATUS_LABEL.submitted },
    { value: 'partially_issued', label: REQUEST_STATUS_LABEL.partially_issued },
    { value: 'issued', label: REQUEST_STATUS_LABEL.issued },
    { value: 'cancelled', label: REQUEST_STATUS_LABEL.cancelled },
];

interface DraftLine {
    key: number;
    item_id: number | null;
    quantity: number | null;
    notes: string;
}

const emptyLine = (key: number): DraftLine => ({ key, item_id: null, quantity: null, notes: '' });

export default function MaterialRequestsPage() {
    const queryClient = useQueryClient();
    const [statusFilter, setStatusFilter] = useState<MaterialRequestStatus | 'all'>('all');
    const [createOpen, setCreateOpen] = useState(false);
    const [lines, setLines] = useState<DraftLine[]>([emptyLine(0)]);
    const [shiftId, setShiftId] = useState<number | null>(null);
    const [workCenterId, setWorkCenterId] = useState<number | null>(null);
    const [notes, setNotes] = useState('');
    const [cancelling, setCancelling] = useState<MaterialRequest | null>(null);
    const [cancelReason, setCancelReason] = useState('');

    const requestsQuery = useQuery({
        queryKey: ['material-flow', 'requests', statusFilter],
        // The floor's own page, so it asks for its drafts. The store's queue
        // does not send this and the server would refuse it there anyway.
        queryFn: () =>
            listMaterialRequests(
                statusFilter === 'all'
                    ? { include_unsubmitted: 1 }
                    : { status: statusFilter, include_unsubmitted: 1 },
            ),
    });
    // Live Production/WIP stock — the second half of the page.
    const floorQuery = useQuery({ queryKey: ['material-flow', 'production-floor-stock'], queryFn: listProductionFloorStock });
    const materialsQuery = useQuery({ queryKey: ['material-flow', 'materials'], queryFn: listRequestableMaterials });
    const shiftsQuery = useQuery({ queryKey: ['production', 'shifts', 'active'], queryFn: () => listShifts(true) });
    const machinesQuery = useQuery({ queryKey: ['production', 'work-centers', 'active'], queryFn: () => listWorkCenters(true) });

    const materialsById = useMemo(() => {
        const map = new Map<number, MaterialFlowMaterial>();
        (materialsQuery.data ?? []).forEach((material) => map.set(material.id, material));
        return map;
    }, [materialsQuery.data]);

    /**
     * The three figures DEC-20260831-001 puts on this screen, per line.
     * Display only — the server recomputes them against the floor as it
     * stands when the request is actually written.
     */
    const netted = useMemo(() => netAgainstProduction(lines, materialsById), [lines, materialsById]);

    const machineDecisionInput = lines.map((line) => (line.item_id === null ? undefined : materialsById.get(line.item_id)));
    const machineApplies = machineAppliesToRequest(machineDecisionInput);
    const machineField = machineFieldDecision(machineApplies);

    const invalidate = () => queryClient.invalidateQueries({ queryKey: ['material-flow'] });

    const resetDraft = () => {
        setLines([emptyLine(0)]);
        setShiftId(null);
        setWorkCenterId(null);
        setNotes('');
    };

    const createMutation = useMutation({
        mutationFn: () =>
            createMaterialRequest({
                shift_id: shiftId,
                work_center_id: machineApplies === true ? workCenterId : null,
                notes: notes.trim() === '' ? null : notes.trim(),
                lines: lines
                    .filter((line) => line.item_id !== null && (line.quantity ?? 0) > 0)
                    .map<CreateMaterialRequestLinePayload>((line) => ({
                        item_id: line.item_id as number,
                        // WHAT PRODUCTION NEEDS. The server subtracts what is
                        // standing on the floor at the moment it writes the
                        // request and stores the balance — this screen shows
                        // the same three figures but never decides them
                        // (DEC-20260831-001).
                        required_quantity: line.quantity as number,
                        quantity: line.quantity as number,
                        notes: line.notes.trim() === '' ? null : line.notes.trim(),
                    })),
            }),
        onSuccess: () => {
            message.success('Request raised. Send it to the store when it is ready.');
            setCreateOpen(false);
            resetDraft();
            invalidate();
        },
        onError: (error) => message.error(apiRefusalMessage(error, 'The request was refused.')),
    });

    const submitMutation = useMutation({
        mutationFn: (request: MaterialRequest) => submitMaterialRequest(request.id),
        onSuccess: () => {
            message.success('Sent to the store. Nothing has moved in stock yet.');
            invalidate();
        },
        onError: (error) => message.error(apiRefusalMessage(error, REFUSAL_MESSAGE.request_closed)),
    });

    const cancelMutation = useMutation({
        mutationFn: () => cancelMaterialRequest((cancelling as MaterialRequest).id, cancelReason.trim()),
        onSuccess: () => {
            setCancelling(null);
            setCancelReason('');
            invalidate();
        },
        onError: (error) => message.error(apiRefusalMessage(error, REFUSAL_MESSAGE.request_closed)),
    });

    const validDraftLines = lines.filter((line) => line.item_id !== null && (line.quantity ?? 0) > 0);

    return (
        <>
            <Space style={{ marginBottom: 16, justifyContent: 'space-between', width: '100%' }} wrap>
                <Typography.Title level={3} style={{ margin: 0 }}>
                    Material Requests
                </Typography.Title>
                <Space wrap>
                    <Select
                        value={statusFilter}
                        onChange={setStatusFilter}
                        options={STATUS_FILTERS}
                        style={{ width: 200 }}
                    />
                    <Button type="primary" onClick={() => setCreateOpen(true)}>
                        New request
                    </Button>
                </Space>
            </Space>

            <Alert type="info" showIcon style={{ marginBottom: 16 }} message={ISSUE_IS_NOT_CONSUMPTION} />

            <Table<MaterialRequest>
                rowKey="id"
                loading={requestsQuery.isLoading}
                dataSource={requestsQuery.data?.data}
                pagination={false}
                scroll={{ x: 'max-content' }}
                locale={{
                    emptyText: (
                        <ListEmpty
                            state={requestsQuery}
                            entity="material requests"
                            empty="No material requests yet. Raise one to ask the store for material."
                        />
                    ),
                }}
                expandable={{
                    expandedRowRender: (request) => <RequestLinesTable lines={request.lines} />,
                }}
                columns={[
                    { title: 'Request', dataIndex: 'request_number' },
                    {
                        title: 'Status',
                        render: (_, request) => (
                            <Tooltip title={REQUEST_STATUS_HELP[request.status]}>
                                <Tag color={REQUEST_STATUS_TONE[request.status]}>{REQUEST_STATUS_LABEL[request.status]}</Tag>
                            </Tooltip>
                        ),
                    },
                    { title: 'Raised by', render: (_, request) => request.requested_by_name ?? '—' },
                    { title: 'Raised at', render: (_, request) => request.requested_at ?? '—' },
                    { title: 'Shift', render: (_, request) => request.shift_name ?? '—' },
                    {
                        title: 'Machine / area',
                        render: (_, request) =>
                            request.work_center_name ?? (
                                <Typography.Text type="secondary">None — common input (FC-01)</Typography.Text>
                            ),
                    },
                    { title: 'Lines', render: (_, request) => request.lines.length },
                    {
                        title: 'Actions',
                        render: (_, request) => (
                            <Space>
                                {request.can.submit ? (
                                    <Tooltip title={TRANSITION_HELP.submit_request}>
                                        <Button
                                            size="small"
                                            type="primary"
                                            loading={submitMutation.isPending}
                                            onClick={() => submitMutation.mutate(request)}
                                        >
                                            {TRANSITION_LABEL.submit_request}
                                        </Button>
                                    </Tooltip>
                                ) : null}
                                {request.can.cancel ? (
                                    <Button size="small" danger onClick={() => setCancelling(request)}>
                                        {TRANSITION_LABEL.cancel_request}
                                    </Button>
                                ) : null}
                            </Space>
                        ),
                    },
                ]}
            />

            {/* ---------------------------------------------------------------
                THE SECOND HALF OF THE PAGE — what the floor ALREADY holds.

                Asked for by the owner so a supervisor can see what is standing
                on the floor before asking the store for more. Sourced from the
                Production/WIP stock BALANCE, never from request history: issues
                only ever go up, so a floor built from them would never empty
                and would send someone to the store for resin they are standing
                next to.

                No machine column, and there will not be one: a bag belongs to
                no machine and no batch (FC-01), so this is per MATERIAL. No day
                bin either — the location is Production/WIP (DEC-20260817-001).
            ---------------------------------------------------------------- */}
            <Typography.Title level={4} style={{ marginTop: 32 }}>
                Material available on the production floor
            </Typography.Title>

            <Table<ProductionFloorStock>
                rowKey="item_id"
                size="small"
                loading={floorQuery.isLoading}
                dataSource={floorQuery.data?.data}
                pagination={false}
                locale={{
                    emptyText: (
                        // THREE different empty tables, and only ONE of them
                        // may say the floor is clear. Reporting a failed
                        // request or an unconfigured location as "everything
                        // has been consumed" is a false statement about stock.
                        // ListEmpty carries the failure (with the server's own
                        // sentence and a retry); the two READY cases stay
                        // domain-worded here.
                        <ListEmpty
                            state={floorQuery}
                            entity="the floor's stock"
                            empty={
                                <Empty
                                    image={Empty.PRESENTED_IMAGE_SIMPLE}
                                    description={
                                        floorQuery.data?.meta.wip_configured === false
                                            ? `No ${LOCATION_LABEL.production_wip} location has been set, so the ERP cannot say what is standing on the floor. Name it in Inventory settings.`
                                            : `Nothing is standing in ${LOCATION_LABEL.production_wip}. Everything issued has been consumed or returned.`
                                    }
                                />
                            }
                        />
                    ),
                }}
                columns={[
                    {
                        title: 'Material',
                        // display_name carried through: rebuilding the input
                        // by hand is what kept the ERP's own name off this
                        // table while the payload was already sending it.
                        render: (_, row) => itemLabel({ sku: row.sku, name: row.name, display_name: row.display_name }),
                    },
                    {
                        title: `In ${LOCATION_LABEL.production_wip}`,
                        align: 'right',
                        render: (_, row) => formatQuantity(row.quantity),
                    },
                    { title: 'UOM', dataIndex: 'uom', render: (uom: string | null) => uom ?? '—' },
                    {
                        title: 'Last issued',
                        render: (_, row) => (row.last_issued_at ? new Date(row.last_issued_at).toLocaleString() : '—'),
                    },
                    { title: 'Issue', render: (_, row) => row.last_issue_number ?? '—' },
                    { title: 'Issued by', render: (_, row) => row.issued_by ?? '—' },
                    { title: 'Received by', render: (_, row) => row.received_by ?? '—' },
                ]}
            />


            <Modal
                open={createOpen}
                width={860}
                title="New material request"
                okText="Raise request"
                okButtonProps={{ loading: createMutation.isPending }}
                onCancel={() => setCreateOpen(false)}
                onOk={() => {
                    if (validDraftLines.length === 0) {
                        message.error(REFUSAL_MESSAGE.quantity_not_positive);
                        return;
                    }
                    createMutation.mutate();
                }}
                destroyOnHidden
            >
                <Row gutter={12} style={{ marginBottom: 12 }}>
                    <Col xs={24} sm={12}>
                        <Typography.Text type="secondary">Shift</Typography.Text>
                        <Select
                            allowClear
                            value={shiftId}
                            onChange={(value) => setShiftId(value ?? null)}
                            style={{ width: '100%' }}
                            placeholder="Which shift is asking?"
                            options={(shiftsQuery.data?.data ?? []).map((shift) => ({ value: shift.id, label: shift.name }))}
                        />
                    </Col>
                    <Col xs={24} sm={12}>
                        <Typography.Text type="secondary">Machine / area</Typography.Text>
                        {machineField.show ? (
                            <Select
                                allowClear
                                value={workCenterId}
                                onChange={(value) => setWorkCenterId(value ?? null)}
                                style={{ width: '100%' }}
                                placeholder="Where is it going?"
                                options={(machinesQuery.data?.data ?? []).map((machine) => ({
                                    value: machine.id,
                                    label: machineLabel(machine),
                                }))}
                            />
                        ) : (
                            <div>
                                <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                                    {machineField.note}
                                </Typography.Text>
                            </div>
                        )}
                    </Col>
                </Row>

                <Card size="small" title="What is needed" style={{ marginBottom: 12 }}>
                    <Space direction="vertical" size={8} style={{ width: '100%' }}>
                        {/*
                          The header carries the three figures' names, once, so
                          the numbers below need no sentence explaining them.
                        */}
                        <Row gutter={8} style={{ display: 'flex' }}>
                            <Col xs={0} sm={8} />
                            <Col xs={0} sm={4}>
                                <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                                    Required
                                </Typography.Text>
                            </Col>
                            <Col xs={0} sm={3}>
                                <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                                    In production
                                </Typography.Text>
                            </Col>
                            <Col xs={0} sm={3}>
                                <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                                    Ask store for
                                </Typography.Text>
                            </Col>
                            <Col xs={0} sm={6} />
                        </Row>
                        {lines.map((line, index) => (
                            <Row key={line.key} gutter={8} align="middle">
                                <Col xs={24} sm={8}>
                                    <Select
                                        showSearch
                                        optionFilterProp="label"
                                        value={line.item_id}
                                        placeholder="Material"
                                        style={{ width: '100%' }}
                                        loading={materialsQuery.isLoading}
                                        onChange={(value: number) =>
                                            setLines((current) =>
                                                current.map((row) => (row.key === line.key ? { ...row, item_id: value } : row)),
                                            )
                                        }
                                        options={(materialsQuery.data ?? []).map((material) => ({
                                            value: material.id,
                                            label: itemLabel(material),
                                        }))}
                                    />
                                </Col>
                                <Col xs={12} sm={4}>
                                    <InputNumber
                                        min={0}
                                        value={line.quantity}
                                        placeholder={
                                            line.item_id === null ? 'Required' : `Required (${materialsById.get(line.item_id)?.uom ?? ''})`
                                        }
                                        style={{ width: '100%' }}
                                        onChange={(value) =>
                                            setLines((current) =>
                                                current.map((row) => (row.key === line.key ? { ...row, quantity: value } : row)),
                                            )
                                        }
                                    />
                                </Col>
                                {/*
                                  THE OTHER TWO FIGURES DEC-20260831-001 REQUIRES.
                                  Read-only, because neither is typed: what is on
                                  the floor is a fact, and the balance follows from
                                  it. A unit the master no longer agrees with shows
                                  the quantity struck through — it IS there, it just
                                  may not be subtracted (FC-03), and a bare 0 would
                                  read as an empty floor.
                                */}
                                <Col xs={6} sm={3}>
                                    <Tooltip
                                        title={
                                            netted[index]?.unitMismatch
                                                ? `Standing in production as ${
                                                      materialsById.get(line.item_id ?? -1)?.uom ?? 'another unit'
                                                  } — not netted`
                                                : 'In production'
                                        }
                                    >
                                        <Typography.Text
                                            type={netted[index]?.unitMismatch ? 'warning' : 'secondary'}
                                            delete={netted[index]?.unitMismatch}
                                        >
                                            {formatQuantity(netted[index]?.available ?? 0)}
                                        </Typography.Text>
                                    </Tooltip>
                                </Col>
                                <Col xs={6} sm={3}>
                                    <Tooltip title="Ask the store for">
                                        <Typography.Text strong>
                                            {formatQuantity(netted[index]?.ask ?? 0)}
                                        </Typography.Text>
                                    </Tooltip>
                                </Col>
                                <Col xs={12} sm={4}>
                                    <Input
                                        value={line.notes}
                                        placeholder="Note (optional)"
                                        onChange={(event) =>
                                            setLines((current) =>
                                                current.map((row) =>
                                                    row.key === line.key ? { ...row, notes: event.target.value } : row,
                                                ),
                                            )
                                        }
                                    />
                                </Col>
                                <Col xs={24} sm={2}>
                                    <Button
                                        size="small"
                                        danger
                                        disabled={lines.length === 1}
                                        onClick={() => setLines((current) => current.filter((row) => row.key !== line.key))}
                                    >
                                        Remove
                                    </Button>
                                </Col>
                            </Row>
                        ))}
                        <Button
                            size="small"
                            onClick={() => setLines((current) => [...current, emptyLine((current.at(-1)?.key ?? 0) + 1)])}
                        >
                            Add a material
                        </Button>
                    </Space>
                </Card>

                <Input.TextArea
                    rows={2}
                    value={notes}
                    onChange={(event) => setNotes(event.target.value)}
                    placeholder="Anything the store should know"
                />
                <Typography.Paragraph type="secondary" style={{ fontSize: 12, marginTop: 8, marginBottom: 0 }}>
                    Raising a request moves nothing in stock. Material leaves the Raw Material Store only when the store
                    issues it, and even then it is issued to production — not consumed.
                </Typography.Paragraph>
            </Modal>

            <Modal
                open={cancelling !== null}
                title={`${TRANSITION_LABEL.cancel_request} ${cancelling?.request_number ?? ''}`}
                okText={TRANSITION_LABEL.cancel_request}
                okButtonProps={{ danger: true, loading: cancelMutation.isPending }}
                onCancel={() => setCancelling(null)}
                onOk={() => {
                    if (cancelReason.trim() === '') {
                        message.error(REFUSAL_MESSAGE.reason_missing);
                        return;
                    }
                    cancelMutation.mutate();
                }}
                destroyOnHidden
            >
                <Typography.Paragraph type="secondary">{REQUEST_STATUS_HELP.cancelled}</Typography.Paragraph>
                <Input.TextArea
                    rows={3}
                    value={cancelReason}
                    onChange={(event) => setCancelReason(event.target.value)}
                    placeholder="Why is the request being withdrawn?"
                />
            </Modal>
        </>
    );
}
