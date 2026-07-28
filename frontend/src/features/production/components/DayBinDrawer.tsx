import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Button, Drawer, InputNumber, message, Modal, Radio, Select, Space, Table, Tag, Typography } from 'antd';
import { useEffect, useState } from 'react';
import BarcodeScanInput from '@/components/barcode/BarcodeScanInput';
import {
    getDayBin,
    getMaterialBagPickList,
    loadDayBin,
    returnDayBin,
    type LoadDayBinPayload,
} from '@/features/production/api';
import type { DayBinLoadedBag, ShiftProductionEntry, WorkCenter } from '@/features/production/types';

/** "10.6000" → "10.6"; "—" for null/unparseable. */
function fmtKg(v: string | null | undefined): string {
    if (v === null || v === undefined || v === '') return '—';
    const n = parseFloat(v);
    return Number.isNaN(n) ? '—' : String(parseFloat(n.toFixed(4)));
}

interface DayBinDrawerProps {
    workCenter: WorkCenter | null;
    /** The running segment movements attach to — null when the machine is idle. */
    entry: ShiftProductionEntry | null;
    open: boolean;
    onClose: () => void;
}

/**
 * The per-machine day-bin ledger (Phase 6 traceability). Rendered ONLY when
 * settings.traceability_enabled is true — the parent gates it, so with the
 * flag off this file contributes nothing to the visible UI.
 *
 * Scanner-gun-first: the barcode input is a plain autofocused text field
 * (hardware scanners type + Enter), camera scan is the built-in fallback of
 * BarcodeScanInput. Scanning acts immediately in the selected mode:
 *   Load   — POST /production/day-bin/load with the barcode (full bag when
 *            the weighed-kg field is empty, partial when set)
 *   Return — POST /production/day-bin/return; kg + material required, the
 *            bag optional (scan resolves it to material_bag_id — the
 *            backend takes ids, not barcodes, on returns)
 */
export default function DayBinDrawer({ workCenter, entry, open, onClose }: DayBinDrawerProps) {
    const [mode, setMode] = useState<'load' | 'return'>('load');
    const [weighedKg, setWeighedKg] = useState<number | null>(null);
    const [returnItemId, setReturnItemId] = useState<number | null>(null);
    const queryClient = useQueryClient();

    const { data: dayBin, isLoading } = useQuery({
        queryKey: ['production', 'day-bin', workCenter?.id],
        queryFn: () => getDayBin(workCenter!.id),
        enabled: open && workCenter !== null,
        refetchInterval: 20000,
    });

    const materials = dayBin?.materials ?? [];

    // Returns need a material; when only one is in the bin it IS the answer.
    useEffect(() => {
        if (materials.length === 1 && returnItemId === null) {
            setReturnItemId(materials[0].item.id);
        }
    }, [materials, returnItemId]);

    const invalidate = () => {
        queryClient.invalidateQueries({ queryKey: ['production', 'day-bin', workCenter?.id] });
        if (entry) queryClient.invalidateQueries({ queryKey: ['production', 'entry-day-bin', entry.id] });
    };

    const loadMutation = useMutation({
        mutationFn: loadDayBin,
        onSuccess: (movement) => {
            invalidate();
            setWeighedKg(null);
            const bag = movement.material_bag;
            message.success(
                `Loaded ${fmtKg(movement.quantity_kg)} kg${bag ? ` from bag ${bag.barcode} (${fmtKg(bag.remaining_kg)} kg left)` : ''}`,
            );
        },
        onError: (error: any, variables: LoadDayBinPayload) => {
            const data = error?.response?.data;
            const msg: string = data?.message ?? 'Unknown error';
            // FIFO refusal — keyed STRICTLY off the machine-readable code the
            // backend puts in the 422 body, never off message text. The
            // override retry itself is still enforced server-side (the
            // production.override-fifo permission, recorded against a name).
            if (!variables.override_fifo && data?.code === 'fifo_order') {
                Modal.confirm({
                    title: 'Older bag still open',
                    content: `${msg} Override FIFO and load this bag anyway? This needs the FIFO-override permission and is recorded against your name.`,
                    okText: 'Override FIFO',
                    okButtonProps: { danger: true },
                    onOk: () => loadMutation.mutate({ ...variables, override_fifo: true }),
                });
                return;
            }
            Modal.error({ title: 'Could not load bag', content: msg });
        },
    });

    const returnMutation = useMutation({
        mutationFn: returnDayBin,
        onSuccess: (movement) => {
            invalidate();
            setWeighedKg(null);
            const bag = movement.material_bag;
            message.success(`Returned ${fmtKg(movement.quantity_kg)} kg${bag ? ` to bag ${bag.barcode}` : ''}`);
        },
        onError: (error: any) => {
            Modal.error({ title: 'Could not return material', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    const handleLoadScan = (code: string) => {
        if (!workCenter) return;
        loadMutation.mutate({
            work_center_id: workCenter.id,
            barcode: code,
            quantity_kg: weighedKg ?? undefined,
            shift_production_entry_id: entry?.id,
        });
    };

    /**
     * Returns go to the backend by bag ID, so a scanned barcode is resolved
     * locally: bags at this machine first (the aggregate lists them), then
     * the item's in-store pick list (a partially loaded bag lives there).
     */
    const handleReturnScan = async (code: string) => {
        if (!workCenter) return;
        if (!weighedKg || weighedKg <= 0) {
            message.warning('Enter the kg going back before scanning the bag.');
            return;
        }
        for (const material of materials) {
            const bag = material.loaded_bags.find((b) => b.barcode === code);
            if (bag) {
                returnMutation.mutate({
                    work_center_id: workCenter.id,
                    item_id: material.item.id,
                    quantity_kg: weighedKg,
                    material_bag_id: bag.id,
                    shift_production_entry_id: entry?.id,
                });
                return;
            }
        }
        if (returnItemId === null) {
            message.warning('Pick the material first — that bag is not at this machine.');
            return;
        }
        try {
            const pickList = await getMaterialBagPickList(returnItemId);
            const bag = pickList.find((b) => b.barcode === code);
            if (!bag) {
                Modal.error({
                    title: 'Bag not found',
                    content: `No bag with barcode "${code}" is at this machine or in the store for the selected material.`,
                });
                return;
            }
            returnMutation.mutate({
                work_center_id: workCenter.id,
                item_id: returnItemId,
                quantity_kg: weighedKg,
                material_bag_id: bag.id,
                shift_production_entry_id: entry?.id,
            });
        } catch (error: any) {
            Modal.error({ title: 'Could not look up the bag', content: error?.response?.data?.message ?? 'Unknown error' });
        }
    };

    const submitting = loadMutation.isPending || returnMutation.isPending;

    const loadedBags: (DayBinLoadedBag & { itemSku: string })[] = materials.flatMap((m) =>
        m.loaded_bags.map((b) => ({ ...b, itemSku: m.item.sku })),
    );

    return (
        <Drawer
            title={`Day Bin — ${workCenter?.name ?? ''}`}
            open={open}
            onClose={onClose}
            width="min(100vw, 480px)"
            destroyOnHidden
        >
            {entry ? (
                <Typography.Paragraph type="secondary" style={{ marginBottom: 12 }}>
                    Running: <Typography.Text strong>{entry.item.sku}</Typography.Text>
                    {entry.batch_number ? ` · Batch ${entry.batch_number}` : ''}
                </Typography.Paragraph>
            ) : (
                <Alert
                    type="warning"
                    showIcon
                    style={{ marginBottom: 12 }}
                    message="No batch running — movements will be logged against the machine only."
                />
            )}

            <Radio.Group
                value={mode}
                onChange={(e) => setMode(e.target.value)}
                optionType="button"
                buttonStyle="solid"
                size="large"
                style={{ marginBottom: 8 }}
                options={[
                    { value: 'load', label: 'Load' },
                    { value: 'return', label: 'Return' },
                ]}
            />

            {mode === 'return' && (
                <Select
                    value={returnItemId}
                    onChange={(v) => setReturnItemId(v)}
                    placeholder="Material going back"
                    style={{ width: '100%', marginBottom: 8 }}
                    options={materials.map((m) => ({ value: m.item.id, label: `${m.item.sku} — ${m.item.name}` }))}
                />
            )}

            <BarcodeScanInput
                onScan={(code) => (mode === 'load' ? handleLoadScan(code) : void handleReturnScan(code))}
                placeholder={mode === 'load' ? 'Scan bag to load…' : 'Scan bag to return to (optional)…'}
                style={{ marginBottom: 8 }}
            />

            <Space style={{ width: '100%', marginBottom: 4 }}>
                <InputNumber
                    min={0}
                    step={0.1}
                    value={weighedKg}
                    onChange={(v) => setWeighedKg(v)}
                    suffix="Kg"
                    style={{ width: 200 }}
                    placeholder={mode === 'load' ? 'Weighed kg' : 'Kg going back'}
                />
                {mode === 'return' && (
                    <Button
                        disabled={!weighedKg || weighedKg <= 0 || returnItemId === null}
                        loading={submitting}
                        onClick={() => {
                            if (!workCenter || !weighedKg || returnItemId === null) return;
                            returnMutation.mutate({
                                work_center_id: workCenter.id,
                                item_id: returnItemId,
                                quantity_kg: weighedKg,
                                shift_production_entry_id: entry?.id,
                            });
                        }}
                    >
                        Return without bag
                    </Button>
                )}
            </Space>
            <Typography.Text type="secondary" style={{ display: 'block', fontSize: 12, marginBottom: 16 }}>
                {mode === 'load'
                    ? 'Leave kg empty to load the FULL bag; enter a weighed kg for a partial load.'
                    : 'Weigh what goes back; scan the bag it returns to, or use "Return without bag".'}
            </Typography.Text>

            <Typography.Text strong>Current balance</Typography.Text>
            {materials.length === 0 && (
                <Typography.Paragraph type="secondary" style={{ marginTop: 4 }}>
                    {isLoading ? 'Loading…' : 'Day bin is empty — scan a bag to load it.'}
                </Typography.Paragraph>
            )}
            {materials.map((m) => (
                <div key={m.item.id} style={{ padding: '6px 0' }}>
                    <Space style={{ justifyContent: 'space-between', width: '100%' }}>
                        <Typography.Text>{m.item.sku} — {m.item.name}</Typography.Text>
                        <Typography.Text strong style={{ whiteSpace: 'nowrap' }}>{fmtKg(m.balance_kg)} kg</Typography.Text>
                    </Space>
                </div>
            ))}

            {loadedBags.length > 0 && (
                <>
                    <Typography.Text strong style={{ display: 'block', marginTop: 16 }}>
                        Bags in this day bin
                    </Typography.Text>
                    <Table<DayBinLoadedBag & { itemSku: string }>
                        rowKey="id"
                        size="small"
                        pagination={false}
                        style={{ marginTop: 8 }}
                        dataSource={loadedBags}
                        columns={[
                            { title: 'Barcode', dataIndex: 'barcode', render: (v: string) => <Typography.Text code>{v}</Typography.Text> },
                            { title: 'Lot', render: (_, b) => b.lot?.supplier_lot_no ?? '—' },
                            { title: 'Item', dataIndex: 'itemSku' },
                            { title: 'Left (kg)', render: (_, b) => <Tag>{fmtKg(b.remaining_kg)}</Tag> },
                        ]}
                    />
                </>
            )}
        </Drawer>
    );
}
