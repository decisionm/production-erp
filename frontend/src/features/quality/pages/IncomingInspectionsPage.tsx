import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Button, DatePicker, Descriptions, Drawer, Empty, Form, Input, Modal, Space, Table, Tag, Typography } from 'antd';
import dayjs from 'dayjs';
import { useState } from 'react';
import {
    INCOMING_INSPECTIONS_QUERY_KEY,
    PENDING_INSPECTION_LINES_QUERY_KEY,
    createIncomingInspection,
    listIncomingInspections,
    listPendingIncomingInspectionLines,
} from '@/features/quality/api';
import {
    closedInspectionModal,
    inspectionPayload,
    nextInspectionState,
    serverErrorsFor,
    validateInspection,
    type InspectionField,
} from '@/features/quality/incomingInspection';
import type { IncomingInspection, InspectionResult, PendingInspectionLine } from '@/features/quality/types';

const resultColor: Record<InspectionResult, string> = {
    pass: 'green',
    fail: 'red',
    partial: 'gold',
};

/**
 * THE INCOMING QUALITY DESK.
 *
 * The pending queue comes FIRST because it is the work; the recorded
 * inspections below it are the record. Every figure, every rule and every
 * state transition in this page comes from `incomingInspection.ts`, which is
 * pure and unit-tested — this file is the wiring and the markup, and it holds
 * no arithmetic and no `Number()` of its own.
 */
export default function IncomingInspectionsPage() {
    const [modal, setModal] = useState(closedInspectionModal);
    const [detailRow, setDetailRow] = useState<IncomingInspection | null>(null);
    const queryClient = useQueryClient();

    const { data: pending, isLoading: pendingLoading } = useQuery({
        queryKey: PENDING_INSPECTION_LINES_QUERY_KEY,
        queryFn: listPendingIncomingInspectionLines,
    });
    const { data, isLoading } = useQuery({
        queryKey: INCOMING_INSPECTIONS_QUERY_KEY,
        queryFn: listIncomingInspections,
    });

    const validation = validateInspection(modal.values);

    const mutation = useMutation({
        mutationFn: createIncomingInspection,
        onSuccess: () => {
            // BOTH lists move: the inspected line leaves the queue and the
            // new record joins the log. Same keys the queries above read, so
            // an invalidation can never miss its own list.
            queryClient.invalidateQueries({ queryKey: PENDING_INSPECTION_LINES_QUERY_KEY });
            queryClient.invalidateQueries({ queryKey: INCOMING_INSPECTIONS_QUERY_KEY });
            setModal((state) => nextInspectionState(state, { type: 'submitted' }));
        },
        onError: (error: unknown) => {
            setModal((state) => nextInspectionState(state, { type: 'failed', error }));
        },
    });

    /** Open, or switch rows — one path, so the reset can never be forgotten. */
    const inspect = (line: PendingInspectionLine) => {
        mutation.reset();
        setModal((state) => nextInspectionState(state, { type: 'inspect', line }));
    };

    const dismiss = (type: 'cancel' | 'close') => {
        mutation.reset();
        setModal((state) => nextInspectionState(state, { type }));
    };

    const change = (field: InspectionField) => (value: string) =>
        setModal((state) => nextInspectionState(state, { type: 'change', field, value }));

    /** Client message first (it is about what is on screen), then the server's. */
    const help = (field: InspectionField) => {
        const messages = [validation.errors[field], ...serverErrorsFor(modal, field)].filter(Boolean);
        return messages.length > 0 ? messages.join(' ') : undefined;
    };
    const status = (field: InspectionField) => (help(field) === undefined ? '' : 'error');

    const quantityField = (field: InspectionField, label: string) => (
        <Form.Item label={label} validateStatus={status(field)} help={help(field)}>
            {/* A plain text input, not InputNumber: the operator's decimal
                string reaches the server unnormalised and unrounded. */}
            <Input
                inputMode="decimal"
                value={modal.values[field]}
                onChange={(event) => change(field)(event.target.value)}
            />
        </Form.Item>
    );

    return (
        <>
            <Space style={{ marginBottom: 16, justifyContent: 'space-between', width: '100%' }}>
                <Typography.Title level={3} style={{ margin: 0 }}>Incoming Quality</Typography.Title>
            </Space>

            <Typography.Title level={5}>Awaiting inspection ({pending?.length ?? 0})</Typography.Title>
            <Table<PendingInspectionLine>
                style={{ marginBottom: 32 }}
                scroll={{ x: 'max-content' }}
                rowKey="id"
                loading={pendingLoading}
                dataSource={pending}
                pagination={false}
                locale={{ emptyText: <Empty description="Nothing waiting for inspection" /> }}
                columns={[
                    { title: 'GRN', dataIndex: 'grn_reference', render: (reference: string | null) => reference ?? '—' },
                    { title: 'Item', render: (_, row) => `${row.item.sku ?? '—'} — ${row.item.name ?? '—'}` },
                    {
                        title: 'Received',
                        align: 'right',
                        render: (_, row) => `${row.received_quantity}${row.uom ? ` ${row.uom}` : ''}`,
                    },
                    {
                        title: 'Actions',
                        render: (_, row) => (
                            <Button type="primary" size="small" onClick={() => inspect(row)}>Inspect</Button>
                        ),
                    },
                ]}
            />

            <Typography.Title level={5}>Recorded inspections</Typography.Title>
            <Table<IncomingInspection>
                scroll={{ x: 'max-content' }}
                rowKey="id"
                loading={isLoading}
                dataSource={data?.data}
                pagination={false}
                columns={[
                    { title: 'Item', render: (_, row) => `${row.item.sku} — ${row.item.name}` },
                    { title: 'Inspected', dataIndex: 'inspected_quantity' },
                    { title: 'Accepted', dataIndex: 'accepted_quantity' },
                    { title: 'Rejected', dataIndex: 'rejected_quantity' },
                    {
                        title: 'Result',
                        dataIndex: 'result',
                        render: (result: InspectionResult) => <Tag color={resultColor[result]}>{result}</Tag>,
                    },
                    { title: 'Date', dataIndex: 'inspection_date' },
                    {
                        title: 'Disposition',
                        render: (_, row) => (
                            <>
                                {row.bag_disposition_note && (
                                    <Typography.Text style={{ display: 'block', fontSize: 12 }}>
                                        {row.bag_disposition_note}
                                    </Typography.Text>
                                )}
                                {row.rejections_out_reference && (
                                    <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                                        Rejections Out ref {row.rejections_out_reference} — recorded; no Tally voucher until its shape is proven.
                                    </Typography.Text>
                                )}
                                {!row.bag_disposition_note && !row.rejections_out_reference && '—'}
                            </>
                        ),
                    },
                    {
                        title: 'Actions',
                        render: (_, row) => (
                            <Button size="small" onClick={() => setDetailRow(row)}>View</Button>
                        ),
                    },
                ]}
            />

            <Modal
                maskClosable={false}
                title={modal.line === null ? 'Inspect' : `Inspect ${modal.line.grn_reference ?? ''} — ${modal.line.item.sku ?? ''}`}
                open={modal.line !== null}
                onCancel={() => dismiss('cancel')}
                afterClose={() => dismiss('close')}
                onOk={() => validation.valid && mutation.mutate(inspectionPayload(modal.values))}
                confirmLoading={mutation.isPending}
                okText="Record inspection"
                okButtonProps={{ disabled: !validation.valid }}
                destroyOnHidden
            >
                {modal.line !== null && (
                    <Form layout="vertical">
                        <Descriptions column={1} size="small" bordered style={{ marginBottom: 16 }}>
                            <Descriptions.Item label="GRN">{modal.line.grn_reference ?? '—'}</Descriptions.Item>
                            <Descriptions.Item label="Item">
                                {modal.line.item.sku ?? '—'} — {modal.line.item.name ?? '—'}
                            </Descriptions.Item>
                            <Descriptions.Item label="Received">
                                {modal.line.received_quantity}{modal.line.uom ? ` ${modal.line.uom}` : ''}
                            </Descriptions.Item>
                        </Descriptions>

                        {quantityField('inspected_quantity', 'Inspected')}
                        {quantityField('accepted_quantity', 'Accepted')}
                        {quantityField('rejected_quantity', 'Rejected')}

                        <Form.Item
                            label="Inspection Date"
                            validateStatus={status('inspection_date')}
                            help={help('inspection_date')}
                        >
                            <DatePicker
                                style={{ width: '100%' }}
                                value={modal.values.inspection_date === '' ? null : dayjs(modal.values.inspection_date)}
                                onChange={(_, dateString) => change('inspection_date')((dateString as string) || '')}
                            />
                        </Form.Item>
                        <Form.Item label="Notes" validateStatus={status('notes')} help={help('notes')}>
                            <Input
                                value={modal.values.notes}
                                onChange={(event) => change('notes')(event.target.value)}
                            />
                        </Form.Item>

                        {/* The server's own sentence when it refused without
                            naming a field — the two quantity rules are
                            DomainExceptions and carry a message, not keys. */}
                        {modal.serverError !== null && (
                            <Alert type="error" showIcon style={{ marginBottom: 12 }} message={modal.serverError.message} />
                        )}

                        <Alert
                            type={validation.valid ? 'success' : 'warning'}
                            showIcon
                            message={validation.valid ? `Result: ${validation.result}` : 'Accepted + rejected must equal inspected'}
                        />
                    </Form>
                )}
            </Modal>

            <Drawer
                title={`Inspection #${detailRow?.id}`}
                open={detailRow !== null}
                onClose={() => setDetailRow(null)}
                width="min(100vw, 480px)"
                destroyOnHidden
            >
                {detailRow && (
                    <Descriptions column={1} size="small" bordered>
                        <Descriptions.Item label="Item">
                            {detailRow.item.sku} — {detailRow.item.name}
                        </Descriptions.Item>
                        <Descriptions.Item label="Result">
                            <Tag color={resultColor[detailRow.result]}>{detailRow.result}</Tag>
                        </Descriptions.Item>
                        <Descriptions.Item label="Inspected Quantity">{detailRow.inspected_quantity}</Descriptions.Item>
                        <Descriptions.Item label="Accepted Quantity">{detailRow.accepted_quantity}</Descriptions.Item>
                        <Descriptions.Item label="Rejected Quantity">{detailRow.rejected_quantity}</Descriptions.Item>
                        <Descriptions.Item label="Inspection Date">{detailRow.inspection_date}</Descriptions.Item>
                        <Descriptions.Item label="Inspected By">{detailRow.inspected_by ?? '—'}</Descriptions.Item>
                        <Descriptions.Item label="Notes">{detailRow.notes ?? '—'}</Descriptions.Item>
                    </Descriptions>
                )}
            </Drawer>
        </>
    );
}
