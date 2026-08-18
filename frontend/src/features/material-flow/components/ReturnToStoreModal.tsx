import { useMutation } from '@tanstack/react-query';
import { Alert, Input, InputNumber, Modal, Space, Table, Typography, message } from 'antd';
import { useState } from 'react';
import { itemLabel } from '@/lib/itemLabel';
import { apiRefusalMessage, returnToStore } from '../api';
import { permitsFractions } from '../words';
import type { StoreIssue, StoreIssueLine } from '../types';
import PersonSelect from './PersonSelect';
import {
    LOCATION_LABEL,
    REFUSAL_MESSAGE,
    STATE_COLUMN_LABEL,
    STATE_HELP,
    TRANSITION_HELP,
    TRANSITION_LABEL,
    formatQuantity,
} from '../words';

/**
 * UNUSED MATERIAL COMING BACK — Production/WIP → Raw Material Store.
 *
 * The fourth state, and the one that is easiest to get wrong: a return is
 * not a negative consumption and not a correction of the issue. It is a
 * second movement, in the opposite direction, of material that never got
 * consumed.
 *
 * NOTHING IS PREFILLED. The quantity boxes start empty even though the ERP
 * knows what went out, because what is coming BACK is a physical fact
 * somebody at the store has to see and enter. A prefilled kg would be the
 * system inventing a return quantity, and a wrong return quantity is a stock
 * balance that lies in both directions at once.
 */
export default function ReturnToStoreModal({
    issue,
    open,
    onClose,
    onChanged,
}: {
    issue: StoreIssue;
    open: boolean;
    onClose: () => void;
    onChanged: () => void;
}) {
    const [quantities, setQuantities] = useState<Record<number, number | null>>({});
    const [receivedBy, setReceivedBy] = useState<number | null>(null);
    const [notes, setNotes] = useState('');

    const close = () => {
        setQuantities({});
        setReceivedBy(null);
        setNotes('');
        onClose();
    };

    const mutation = useMutation({
        mutationFn: () =>
            returnToStore(issue.id, {
                received_by: receivedBy,
                notes: notes.trim() === '' ? null : notes.trim(),
                lines: Object.entries(quantities)
                    .filter(([, quantity]) => typeof quantity === 'number' && quantity > 0)
                    .map(([lineId, quantity]) => ({ store_issue_line_id: Number(lineId), quantity: quantity as number })),
            }),
        onSuccess: () => {
            message.success('Return accepted. The material stands in the Raw Material Store again.');
            onChanged();
            close();
        },
        onError: (error) => message.error(apiRefusalMessage(error, REFUSAL_MESSAGE.return_exceeds_issued)),
    });

    const submit = () => {
        const entered = Object.values(quantities).filter((quantity) => typeof quantity === 'number' && quantity > 0);
        if (entered.length === 0) {
            message.error(REFUSAL_MESSAGE.quantity_not_positive);
            return;
        }
        mutation.mutate();
    };

    return (
        <Modal
            open={open}
            width={760}
            title={`${TRANSITION_LABEL.return_to_store} — handover ${issue.issue_number}`}
            okText={TRANSITION_LABEL.return_to_store}
            okButtonProps={{ loading: mutation.isPending }}
            onCancel={close}
            onOk={submit}
            destroyOnHidden
        >
            <Alert
                type="info"
                showIcon
                style={{ marginBottom: 12 }}
                message={TRANSITION_HELP.return_to_store}
                description={STATE_HELP.returned_to_store}
            />

            <Table<StoreIssueLine>
                size="small"
                rowKey="id"
                pagination={false}
                dataSource={issue.lines}
                scroll={{ x: 'max-content' }}
                columns={[
                    { title: 'Material', render: (_, line) => itemLabel({ sku: line.item_sku, name: line.item_name }) },
                    {
                        title: 'Left the store on this handover',
                        align: 'right',
                        render: (_, line) => formatQuantity(line.quantity_issued, line.uom),
                    },
                    {
                        title: 'Already returned',
                        align: 'right',
                        render: (_, line) => formatQuantity(line.quantity_returned, line.uom),
                    },
                    {
                        title: STATE_COLUMN_LABEL.issued_to_production,
                        align: 'right',
                        render: (_, line) => formatQuantity(line.quantity_outstanding, line.uom),
                    },
                    {
                        title: `Coming back to ${LOCATION_LABEL.raw_material_store} now`,
                        align: 'right',
                        render: (_, line) => (
                            <InputNumber
                                min={0}
                                // The issue side refuses half a tray at the
                                // input; so must the return side. It is the
                                // same stock, and a fractional return put
                                // fractional counted quantities in BOTH
                                // locations at once until the server started
                                // refusing it.
                                // ...unless a fractional quantity is ALREADY
                                // standing on the floor. The server allows the
                                // whole outstanding balance back whatever its
                                // unit says, precisely so such a line is never
                                // stranded — and rounding the input to a whole
                                // number here made that escape hatch untypable,
                                // which is the half of the rule that matters.
                                precision={
                                    permitsFractions(line.uom) || Number(line.quantity_outstanding) % 1 !== 0
                                        ? undefined
                                        : 0
                                }
                                step={permitsFractions(line.uom) ? undefined : 1}
                                value={quantities[line.id] ?? null}
                                onChange={(value) => setQuantities((current) => ({ ...current, [line.id]: value }))}
                                placeholder={line.uom ?? 'qty'}
                                style={{ width: 140 }}
                            />
                        ),
                    },
                ]}
            />

            <Space direction="vertical" size={8} style={{ width: '100%', marginTop: 12 }}>
                <PersonSelect
                    value={receivedBy}
                    onChange={setReceivedBy}
                    placeholder="Received back by — the store person accepting it"
                />
                <Input.TextArea
                    rows={2}
                    value={notes}
                    onChange={(event) => setNotes(event.target.value)}
                    placeholder="Why is it coming back? (optional, kept with the record)"
                />
                <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                    Enter what is physically coming back. Nothing is filled in for you.
                </Typography.Text>
            </Space>
        </Modal>
    );
}
