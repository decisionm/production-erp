import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Button, Drawer, InputNumber, message, Select, Space, Typography } from 'antd';
import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { listAllWarehouses } from '@/features/inventory/api';
import { getFactoryDayBin, loadFactoryDayBin } from '@/features/production/api';
import type { FactoryDayBinMaterial, FactoryDayBinSummaryRow } from '@/features/production/types';
import { guessRawMaterialStoreId } from '@/features/production/warehouses';
import { itemLabel } from '@/lib/itemLabel';

/** "10.6000" → "10.6"; "—" for null/unparseable. */
function fmtKg(v: string | null | undefined): string {
    if (v === null || v === undefined || v === '') return '—';
    const n = parseFloat(v);
    return Number.isNaN(n) ? '—' : String(parseFloat(n.toFixed(4)));
}

function toNum(v: string | null | undefined): number {
    const n = parseFloat(v ?? '');
    return Number.isNaN(n) ? 0 : n;
}

interface DayBinDrawerProps {
    open: boolean;
    onClose: () => void;
}

/**
 * THE FACTORY DAY BIN, seen from the machine card. ONE bin feeds all ten
 * machines by crane — there is no per-machine bin, so this drawer takes no
 * work center and never puts a machine name in its title. It used to read
 * GET /production/work-centers/{id}/day-bin and call itself
 * "Day Bin — Machine 1", which is the thing the owner rejected outright.
 *
 * The read is the SAME single source the Day Bin page uses —
 * getFactoryDayBin() / GET /production/factory-day-bin, on the same query key
 * so both surfaces refresh together:
 *   materials  the bin warehouse's own stock balances (kg in the bin now),
 *   summary    per raw material, bin vs store kg plus the registered bags
 *              still holding material — rendered only where the backend
 *              sends it (a row with no summary shows the bin figure alone,
 *              never a "0 kg in store" it cannot know).
 *
 * LOADING IS NOT DONE HERE and is not done per machine: bags are scanned in
 * once for the whole factory with Load Material on the Shift Floor (or on the
 * Day Bin page). Balances here are read-only.
 *
 * RETURNS — material coming back out of the bin — are a real need and stay,
 * central like everything else: a plain stock transfer day-bin warehouse →
 * store, the exact mirror of the load, through Inventory's existing transfer
 * endpoint (loadFactoryDayBin, the same function the Day Bin page's manual
 * load posts).
 *
 * Why NOT POST /production/day-bin/return: that endpoint appends to the
 * PER-MACHINE day_bin_movements ledger, whose `return` rows are load-bearing
 * (DayBinLedgerService::consumptionFor subtracts returned_kg, segmentHeadroom
 * caps closing counts by it, and four backend test files assert it) — so the
 * ledger and every reader of it are left completely untouched. But it cannot
 * serve the central bin: it requires a work_center_id this drawer no longer
 * has, and its guard refuses any return above balanceFor(machine, item),
 * which is 0.0000 now that loads are central (POST /day-bin/load-bag writes a
 * warehouse transfer and deliberately no ledger row). A ledger return here
 * would fail every time.
 */
export default function DayBinDrawer({ open, onClose }: DayBinDrawerProps) {
    const [returnItemId, setReturnItemId] = useState<number | null>(null);
    const [returnWarehouseId, setReturnWarehouseId] = useState<number | null>(null);
    const [returnKg, setReturnKg] = useState<number | null>(null);
    const queryClient = useQueryClient();

    // The one central read — same key as the Day Bin page, so a load done
    // there is on screen here and vice versa.
    const { data: dayBin, isLoading } = useQuery({
        queryKey: ['production', 'factory-day-bin'],
        queryFn: getFactoryDayBin,
        enabled: open,
        refetchInterval: 20000,
    });

    // Where a return goes. Inventory's read: a production-only login 403s on
    // it, which is a normal answer — balances still show, the return form
    // just says why it cannot offer a destination.
    const { data: warehouses, isError: warehousesUnavailable } = useQuery({
        queryKey: ['inventory', 'warehouses', 'all'],
        queryFn: listAllWarehouses,
        enabled: open,
        retry: false,
    });

    const binWarehouse = dayBin?.warehouse ?? null;
    const materials = dayBin?.materials ?? [];

    /**
     * summary keyed by item_id — joined by id, never by position: `materials`
     * is every balance row in the bin warehouse (zero rows kept) while
     * `summary` is the kg-uom raw materials in the bin OR the store, in name
     * order. Different sets, different order.
     */
    const summaryByItem = useMemo(() => {
        const map = new Map<number, FactoryDayBinSummaryRow>();
        for (const row of dayBin?.summary ?? []) map.set(row.item_id, row);
        return map;
    }, [dayBin]);

    const materialLabel = (m: FactoryDayBinMaterial) => (m.item ? itemLabel(m.item) : `Item #${m.item_id}`);

    // A return needs a material; when only one is in the bin it IS the answer.
    useEffect(() => {
        if (materials.length === 1 && returnItemId === null) setReturnItemId(materials[0].item_id);
    }, [materials, returnItemId]);

    // The bin can never be its own destination — the transfer endpoint
    // refuses from === to, so it must not be offerable.
    const destinationOptions = useMemo(
        () =>
            (warehouses?.data ?? [])
                .filter((w) => w.is_active && w.id !== binWarehouse?.id)
                .map((w) => ({ value: w.id, label: `${w.code} — ${w.name}` })),
        [warehouses, binWarehouse],
    );

    // Default the destination to the raw-material store once the list lands,
    // never overwriting a choice already made — and leaving it EMPTY when the
    // masters do not say which warehouse that is. The previous rule preferred
    // any name containing "store" and so offered FG Store, the bottle
    // warehouse, as the place to send resin back to.
    useEffect(() => {
        if (!open || warehouses === undefined || returnWarehouseId !== null) return;
        const storeId = guessRawMaterialStoreId(warehouses.data, binWarehouse?.id ?? null);
        if (storeId !== undefined) setReturnWarehouseId(storeId);
    }, [open, warehouses, binWarehouse, returnWarehouseId]);

    const selected = materials.find((m) => m.item_id === returnItemId) ?? null;
    const availableKg = selected !== null ? toNum(selected.quantity_kg) : 0;
    const overBalance = returnKg !== null && returnKg > availableKg;

    const returnMutation = useMutation({
        mutationFn: (values: { item_id: number; to_warehouse_id: number; quantity: number }) =>
            loadFactoryDayBin({
                item_id: values.item_id,
                from_warehouse_id: binWarehouse!.id,
                to_warehouse_id: values.to_warehouse_id,
                quantity: values.quantity,
                reference: 'Day bin return',
            }),
        onSuccess: (_data, values) => {
            message.success(`Returned ${fmtKg(String(values.quantity))} kg to the store`);
            setReturnKg(null);
            queryClient.invalidateQueries({ queryKey: ['production', 'factory-day-bin'] });
            queryClient.invalidateQueries({ queryKey: ['inventory', 'stock-balances'] });
        },
        onError: (error: any) => {
            if (error?.response?.status === 403) {
                message.error('You do not have permission to move stock (needs Inventory: Manage).');
                return;
            }
            message.error(error?.response?.data?.message ?? 'Could not return the material');
        },
    });

    const canReturn =
        binWarehouse !== null &&
        returnItemId !== null &&
        returnWarehouseId !== null &&
        returnKg !== null &&
        returnKg > 0 &&
        !overBalance;

    return (
        <Drawer title="Day Bin — factory" open={open} onClose={onClose} width="min(100vw, 480px)" destroyOnHidden>
            {/* One bin, one scan point. Naming WHERE rather than only refusing:
                a supervisor who finds no Load button here needs to know the
                factory scans material in once, not per machine. */}
            <Alert
                type="info"
                showIcon
                style={{ marginBottom: 12 }}
                message="One bin for all machines"
                description={
                    <>
                        Resin is scanned in once for the whole factory — use <Typography.Text strong>Load
                        Material</Typography.Text> at the top of this Shift Floor page, not per machine. The{' '}
                        <Link to="/production/day-bin">Day Bin page</Link> shows the full picture: what is in the bin,
                        what is still in the store and everything loaded today. This drawer is the balance, plus
                        sending material back to the store.
                    </>
                }
            />

            {binWarehouse === null && !isLoading && (
                <Alert
                    type="warning"
                    showIcon
                    style={{ marginBottom: 12 }}
                    message="No day-bin warehouse named yet"
                    description={
                        <>
                            Name the warehouse that is the factory day bin on the{' '}
                            <Link to="/production/day-bin">Day Bin page</Link> — until then there is no balance to
                            show and nothing to return.
                        </>
                    }
                />
            )}

            <Typography.Text strong>In the bin now</Typography.Text>
            {materials.length === 0 && (
                <Typography.Paragraph type="secondary" style={{ marginTop: 4 }}>
                    {isLoading
                        ? 'Loading…'
                        : binWarehouse === null
                          ? 'Not configured.'
                          : 'The day bin is empty — load material before starting a batch.'}
                </Typography.Paragraph>
            )}
            {materials.map((m) => {
                const summary = summaryByItem.get(m.item_id);
                const bags = summary?.unopened_bags ?? null;
                const unit = m.item?.uom ?? 'Kg';

                return (
                    <div key={m.item_id} style={{ padding: '6px 0' }}>
                        <Space style={{ justifyContent: 'space-between', width: '100%' }}>
                            <Typography.Text>{materialLabel(m)}</Typography.Text>
                            <Typography.Text strong style={{ whiteSpace: 'nowrap' }}>
                                {fmtKg(m.quantity_kg)} {unit}
                            </Typography.Text>
                        </Space>
                        {/* Store kg and bags only where the backend actually
                            sends the summary row — an unknown store balance
                            must never read as an empty store. */}
                        {summary !== undefined && (
                            <Typography.Text type="secondary" style={{ display: 'block', fontSize: 12 }}>
                                In store {fmtKg(summary.store_kg)} {unit}
                                {bags !== null && bags !== undefined
                                    ? ` · ${bags.count} unopened bag${bags.count === 1 ? '' : 's'} (${fmtKg(bags.kg)} ${unit})`
                                    : ''}
                            </Typography.Text>
                        )}
                    </div>
                );
            })}

            <Typography.Text strong style={{ display: 'block', marginTop: 20 }}>
                Return material to store
            </Typography.Text>
            <Typography.Text type="secondary" style={{ display: 'block', fontSize: 12, marginBottom: 8 }}>
                Weigh what comes out of the bin. This moves it from the day bin back to the store — the mirror of
                Load Material, not a consumption.
            </Typography.Text>

            {warehousesUnavailable && (
                <Alert
                    type="warning"
                    showIcon
                    style={{ marginBottom: 8 }}
                    message="Cannot list the store warehouses with this login (needs Inventory access), so material cannot be returned from here."
                />
            )}

            <Select
                value={returnItemId}
                onChange={(v) => setReturnItemId(v)}
                placeholder="Material going back"
                style={{ width: '100%', marginBottom: 8 }}
                disabled={binWarehouse === null || materials.length === 0}
                options={materials.map((m) => ({ value: m.item_id, label: materialLabel(m) }))}
            />

            <Select
                value={returnWarehouseId}
                onChange={(v) => setReturnWarehouseId(v)}
                placeholder="Store it goes back to"
                style={{ width: '100%', marginBottom: 8 }}
                disabled={binWarehouse === null || destinationOptions.length === 0}
                options={destinationOptions}
            />

            <Space style={{ width: '100%', marginBottom: 4 }}>
                <InputNumber
                    min={0}
                    step={0.1}
                    value={returnKg}
                    onChange={(v) => setReturnKg(v)}
                    suffix="Kg"
                    style={{ width: 200 }}
                    placeholder="Kg going back"
                    disabled={binWarehouse === null}
                />
                <Button
                    disabled={!canReturn}
                    loading={returnMutation.isPending}
                    onClick={() => {
                        if (!canReturn) return;
                        returnMutation.mutate({
                            item_id: returnItemId!,
                            to_warehouse_id: returnWarehouseId!,
                            quantity: returnKg!,
                        });
                    }}
                >
                    Return to store
                </Button>
            </Space>
            {overBalance && selected !== null && (
                <Typography.Text type="danger" style={{ display: 'block', fontSize: 12 }}>
                    Only {fmtKg(selected.quantity_kg)} {selected.item?.uom ?? 'Kg'} of {materialLabel(selected)} is in
                    the bin.
                </Typography.Text>
            )}
        </Drawer>
    );
}
