import { useMutation, useQuery } from '@tanstack/react-query';
import { Descriptions, Form, InputNumber, Modal, Typography } from 'antd';
import { useEffect, useState } from 'react';
import { getDayBin, handoverShiftProductionEntry } from '@/features/production/api';
import type { Shift, ShiftProductionEntry } from '@/features/production/types';

function fmtKg(v: string | null | undefined): string {
    if (v === null || v === undefined || v === '') return '—';
    const n = parseFloat(v);
    return Number.isNaN(n) ? '—' : String(parseFloat(n.toFixed(4)));
}

interface HandoverModalProps {
    /** The outgoing running segment being handed over — null closes the modal. */
    entry: ShiftProductionEntry | null;
    /** The shift taking over (the page's effective shift — change the shift picker first if wrong). */
    incomingShift: Shift | undefined;
    /** The incoming shift's production date (shift-aware). */
    productionDate: string;
    onClose: () => void;
    /** Called after a successful handover — invalidate + close. */
    onDone: () => void;
}

/**
 * Shift takeover (Phase 6 traceability, design §Supervisor experience):
 * the outgoing segment is COMPLETED here — so the backend requires its
 * completion figures (quantity produced at minimum, mirroring
 * CompleteBatchRequest) plus the closing day-bin weighment per material.
 * The incoming supervisor sees exactly what they inherit — batch, product,
 * day-bin lots and balance — and the closing counts they confirm become
 * the new segment's opening. Either supervisor may confirm (handover
 * ownership is Vincent Q5 — the app deliberately doesn't pre-decide).
 */
export default function HandoverModal({ entry, incomingShift, productionDate, onClose, onDone }: HandoverModalProps) {
    const [quantityProduced, setQuantityProduced] = useState<number | null>(null);
    const [quantityScrap, setQuantityScrap] = useState<number | null>(null);
    const [closingKg, setClosingKg] = useState<Record<number, number | null>>({});

    const { data: dayBin } = useQuery({
        queryKey: ['production', 'day-bin', entry?.work_center.id],
        queryFn: () => getDayBin(entry!.work_center.id),
        enabled: entry !== null,
    });

    // Fresh figures per handover — the modal is reused across machines.
    useEffect(() => {
        setQuantityProduced(null);
        setQuantityScrap(null);
        setClosingKg({});
    }, [entry?.id]);

    const materials = dayBin?.materials ?? [];

    const mutation = useMutation({
        mutationFn: () => {
            if (!entry || !incomingShift || !quantityProduced) throw new Error('Missing entry, shift or quantity');
            const closing = materials
                .map((m) => ({ item_id: m.item.id, quantity_kg: closingKg[m.item.id] }))
                .filter((c): c is { item_id: number; quantity_kg: number } => typeof c.quantity_kg === 'number');
            return handoverShiftProductionEntry(entry.id, {
                shift_id: incomingShift.id,
                production_date: productionDate,
                closing_day_bin: closing.length > 0 ? closing : undefined,
                completion: {
                    quantity_produced: quantityProduced,
                    quantity_scrap: quantityScrap ?? undefined,
                },
            });
        },
        onSuccess: onDone,
        onError: (error: any) => {
            Modal.error({
                title: 'Could not hand over the shift',
                content: error?.response?.data?.message ?? 'The batch may have just been completed — refresh and try again.',
            });
        },
    });

    const lots = Array.from(
        new Set(materials.flatMap((m) => m.loaded_bags.map((b) => b.lot?.supplier_lot_no).filter(Boolean))),
    ) as string[];

    return (
        <Modal
            maskClosable={false}
            title={`Hand Over Shift — ${entry?.work_center.name ?? ''}`}
            open={entry !== null}
            onCancel={onClose}
            onOk={() => mutation.mutate()}
            okText="Confirm handover"
            okButtonProps={{ disabled: !incomingShift || !quantityProduced || quantityProduced <= 0 }}
            confirmLoading={mutation.isPending}
            destroyOnHidden
        >
            <Typography.Paragraph type="secondary">
                The running batch continues under the same batch number: this completes the outgoing segment with
                the figures below and opens a new one for the incoming shift, carrying the day-bin closing in as
                its opening.
            </Typography.Paragraph>
            {entry && (
                <Descriptions column={1} size="small" bordered>
                    <Descriptions.Item label="Incoming shift">
                        {incomingShift ? `${incomingShift.name} · ${productionDate}` : 'Pick a shift on the Shift Floor first'}
                    </Descriptions.Item>
                    <Descriptions.Item label="Batch">{entry.batch_number ?? '—'}</Descriptions.Item>
                    <Descriptions.Item label="Product">{`${entry.item.sku} — ${entry.item.name}`}</Descriptions.Item>
                    <Descriptions.Item label="Lots in day bin">{lots.length > 0 ? lots.join(', ') : '—'}</Descriptions.Item>
                    <Descriptions.Item label="Day-bin balance">
                        {materials.length > 0
                            ? materials.map((m) => `${m.item.sku}: ${fmtKg(m.balance_kg)} kg`).join(' · ')
                            : '—'}
                    </Descriptions.Item>
                </Descriptions>
            )}

            <Form layout="vertical" style={{ marginTop: 16 }}>
                <Typography.Text strong>Outgoing segment — completion figures</Typography.Text>
                <Form.Item label="Quantity produced this segment (Nos)" required style={{ marginTop: 8, marginBottom: 8 }}>
                    <InputNumber
                        min={1}
                        value={quantityProduced}
                        onChange={(v) => setQuantityProduced(v)}
                        style={{ width: '100%' }}
                        placeholder="Pieces produced by the outgoing shift"
                    />
                </Form.Item>
                <Form.Item label="Rejection (Nos, optional)" style={{ marginBottom: 8 }}>
                    <InputNumber
                        min={0}
                        value={quantityScrap}
                        onChange={(v) => setQuantityScrap(v)}
                        style={{ width: '100%' }}
                        placeholder="Rejected pieces, if any"
                    />
                </Form.Item>

                {materials.length > 0 && (
                    <>
                        <Typography.Text strong>Closing day-bin weighment</Typography.Text>
                        <Typography.Paragraph type="secondary" style={{ fontSize: 12, marginBottom: 8 }}>
                            Weigh what is left in the machine's day bin per material — this closes the outgoing
                            segment's consumption and opens the incoming one with the same figure.
                        </Typography.Paragraph>
                        {materials.map((m) => (
                            <Form.Item
                                key={m.item.id}
                                label={`${m.item.sku} — ${m.item.name} (balance ${fmtKg(m.balance_kg)} kg)`}
                                style={{ marginBottom: 8 }}
                            >
                                <InputNumber
                                    min={0}
                                    step={0.1}
                                    suffix="Kg"
                                    value={closingKg[m.item.id] ?? null}
                                    onChange={(v) => setClosingKg((prev) => ({ ...prev, [m.item.id]: v }))}
                                    style={{ width: '100%' }}
                                    placeholder="Weighed closing kg (optional)"
                                />
                            </Form.Item>
                        ))}
                    </>
                )}
            </Form>
        </Modal>
    );
}
