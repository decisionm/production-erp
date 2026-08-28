import { useMutation } from '@tanstack/react-query';
import { Alert, Button, Card, Descriptions, Empty, Input, InputNumber, Modal, Space, Table, Tag, Typography, message } from 'antd';
import { useState } from 'react';
import BarcodeScanInput from '@/components/barcode/BarcodeScanInput';
import { itemLabel } from '@/lib/itemLabel';
import { apiRefusalMessage, apiStatus, cancelStoreIssue, completeStoreIssue, scanBagForIssue } from '../api';
import type { StoreIssue, StoreIssueBagScan, StoreIssueLine } from '../types';
import PersonSelect from './PersonSelect';
import {
    ISSUE_STATUS_HELP,
    ISSUE_STATUS_LABEL,
    ISSUE_STATUS_TONE,
    REFUSAL_MESSAGE,
    STATE_COLUMN_LABEL,
    TRACE_STOPS_AT_THE_ISSUE,
    TRANSITION_LABEL,
    formatQuantity,
} from '../words';

/**
 * THE HANDOVER — the store scans the bags it is putting into production's
 * hands, and both names go on the record: who issued it, and who received it.
 *
 * The three states are on this panel as three separate columns, because this
 * is where they are easiest to collapse: what left the store, what came back,
 * and what is STANDING IN Production/WIP right now. None of them is
 * consumption, and there is deliberately no consumption column here — what a
 * batch used is the batch's calculation, on another screen.
 *
 * The panel never says a batch used these bags, and there is nowhere on it to
 * claim that (FC-01): bags are attached to the ISSUE, and the note under the
 * table says so in plain words. Nothing here shows what a bag was bought for
 * or who supplied it — FC-06 keeps that away from a store screen.
 */
/**
 * The queue's list endpoint doesn't load the scans relation, so `bag_scans`
 * arrives absent — not empty — on a freshly issued handover. An absent list
 * is shown as no scans; it is never read for `.length` directly.
 */
export const bagScansOf = (issue: Pick<StoreIssue, 'bag_scans'>): StoreIssueBagScan[] => issue.bag_scans ?? [];

export default function HandoverPanel({ issue, onChanged }: { issue: StoreIssue; onChanged: () => void }) {
    const [receivedBy, setReceivedBy] = useState<number | null>(issue.received_by);
    const [quantityKg, setQuantityKg] = useState<number | null>(null);
    const [cancelOpen, setCancelOpen] = useState(false);
    const [cancelReason, setCancelReason] = useState('');

    const scanMutation = useMutation({
        mutationFn: (barcode: string) =>
            scanBagForIssue(issue.id, { barcode, quantity_kg: quantityKg, received_by: receivedBy }),
        onSuccess: () => {
            setQuantityKg(null);
            onChanged();
        },
        onError: (error) =>
            message.error(
                apiRefusalMessage(
                    error,
                    apiStatus(error) === 404 ? REFUSAL_MESSAGE.bag_scanning_unavailable : REFUSAL_MESSAGE.bag_not_found,
                ),
            ),
    });

    const completeMutation = useMutation({
        mutationFn: () => completeStoreIssue(issue.id),
        onSuccess: () => {
            message.success('Handover closed. What production kept is issued stock, not consumption.');
            onChanged();
        },
        onError: (error) => message.error(apiRefusalMessage(error, REFUSAL_MESSAGE.issue_already_completed)),
    });

    const cancelMutation = useMutation({
        mutationFn: () => cancelStoreIssue(issue.id, cancelReason.trim()),
        onSuccess: () => {
            setCancelOpen(false);
            setCancelReason('');
            onChanged();
        },
        onError: (error) => message.error(apiRefusalMessage(error, REFUSAL_MESSAGE.issue_cancelled)),
    });

    const lineItemLabel = (line: StoreIssueLine) => itemLabel({ sku: line.item_sku, name: line.item_name });
    const bagScans = bagScansOf(issue);

    return (
        <Card
            size="small"
            title={
                <Space wrap>
                    <span>Handover {issue.issue_number}</span>
                    <Tag color={ISSUE_STATUS_TONE[issue.status]}>{issue.state_label ?? ISSUE_STATUS_LABEL[issue.status]}</Tag>
                </Space>
            }
            extra={
                issue.is_open ? (
                    <Space>
                        <Button type="primary" loading={completeMutation.isPending} onClick={() => completeMutation.mutate()}>
                            {TRANSITION_LABEL.complete_issue}
                        </Button>
                        <Button danger onClick={() => setCancelOpen(true)}>
                            {TRANSITION_LABEL.cancel_issue}
                        </Button>
                    </Space>
                ) : null
            }
            style={{ marginBottom: 16 }}
        >
            <Typography.Paragraph type="secondary">{ISSUE_STATUS_HELP[issue.status]}</Typography.Paragraph>

            <Descriptions size="small" column={{ xs: 1, sm: 2 }} style={{ marginBottom: 12 }}>
                <Descriptions.Item label="Issued by (store)">{issue.issued_by_name ?? '—'}</Descriptions.Item>
                <Descriptions.Item label="Issued at">{issue.issued_at ?? '—'}</Descriptions.Item>
                <Descriptions.Item label="Received by (production)">{issue.received_by_name ?? '—'}</Descriptions.Item>
                <Descriptions.Item label="Closed at">{issue.closed_at ?? '—'}</Descriptions.Item>
                <Descriptions.Item label="Cancellation reason">{issue.cancellation_reason ?? '—'}</Descriptions.Item>
                <Descriptions.Item label="Notes">{issue.notes ?? '—'}</Descriptions.Item>
            </Descriptions>

            <Table<StoreIssueLine>
                size="small"
                rowKey="id"
                pagination={false}
                dataSource={issue.lines}
                scroll={{ x: 'max-content' }}
                style={{ marginBottom: 12 }}
                columns={[
                    { title: 'Material', render: (_, line) => lineItemLabel(line) },
                    {
                        title: 'Left the store on this handover',
                        align: 'right',
                        render: (_, line) => formatQuantity(line.quantity_issued, line.uom),
                    },
                    {
                        title: STATE_COLUMN_LABEL.returned_to_store,
                        align: 'right',
                        render: (_, line) => formatQuantity(line.quantity_returned, line.uom),
                    },
                    {
                        title: STATE_COLUMN_LABEL.issued_to_production,
                        align: 'right',
                        render: (_, line) => formatQuantity(line.quantity_outstanding, line.uom),
                    },
                    {
                        title: 'Still to issue on the request',
                        align: 'right',
                        render: (_, line) => formatQuantity(line.quantity_remaining_on_request, line.uom),
                    },
                ]}
            />

            {issue.is_open ? (
                <Space direction="vertical" size={8} style={{ width: '100%', marginBottom: 12 }}>
                    <PersonSelect
                        value={receivedBy}
                        onChange={setReceivedBy}
                        placeholder="Received by — the person on the floor taking the bags"
                        style={{ width: 420 }}
                    />
                    <Space wrap>
                        <BarcodeScanInput
                            onScan={(code) => scanMutation.mutate(code)}
                            placeholder="Scan the bag barcode…"
                            style={{ width: 320 }}
                        />
                        <InputNumber
                            value={quantityKg}
                            onChange={(value) => setQuantityKg(value)}
                            min={0}
                            placeholder="Part bag? kg handed over"
                            style={{ width: 220 }}
                        />
                    </Space>
                    <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                        Leave the kg blank for a whole bag — the server reads the bag's own kilograms. Nothing is filled
                        in for you: a weight the ERP does not know is left empty, never guessed.
                    </Typography.Text>
                </Space>
            ) : null}

            {bagScans.length === 0 ? (
                <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="No bags scanned against this handover yet." />
            ) : (
                <Table<StoreIssueBagScan>
                    size="small"
                    rowKey="id"
                    pagination={false}
                    dataSource={bagScans}
                    scroll={{ x: 'max-content' }}
                    columns={[
                        { title: 'Time', render: (_, scan) => scan.scanned_at ?? '—' },
                        { title: 'Bag', render: (_, scan) => scan.barcode ?? `#${scan.material_bag_id ?? '—'}` },
                        { title: 'Lot', render: (_, scan) => scan.supplier_lot_no ?? '—' },
                        { title: 'kg', align: 'right', render: (_, scan) => formatQuantity(scan.quantity_kg, 'kg') },
                        { title: 'Issued by', render: (_, scan) => scan.issued_by_name ?? '—' },
                        { title: 'Received by', render: (_, scan) => scan.received_by_name ?? '—' },
                    ]}
                />
            )}

            <Alert type="info" showIcon style={{ marginTop: 12 }} message={TRACE_STOPS_AT_THE_ISSUE} />

            <Modal
                open={cancelOpen}
                title={TRANSITION_LABEL.cancel_issue}
                okText={TRANSITION_LABEL.cancel_issue}
                okButtonProps={{ danger: true, loading: cancelMutation.isPending }}
                onCancel={() => setCancelOpen(false)}
                onOk={() => {
                    if (cancelReason.trim() === '') {
                        message.error(REFUSAL_MESSAGE.reason_missing);
                        return;
                    }
                    cancelMutation.mutate();
                }}
                destroyOnHidden
            >
                <Typography.Paragraph type="secondary">{REFUSAL_MESSAGE.reason_missing}</Typography.Paragraph>
                <Input.TextArea
                    rows={3}
                    value={cancelReason}
                    onChange={(event) => setCancelReason(event.target.value)}
                    placeholder="Why is this handover being called off?"
                />
            </Modal>
        </Card>
    );
}
