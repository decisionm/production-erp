import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
    Alert,
    Button,
    Card,
    Col,
    Collapse,
    DatePicker,
    Descriptions,
    Empty,
    Form,
    Input,
    InputNumber,
    Row,
    Select,
    Space,
    Table,
    Tag,
    Typography,
    message,
} from 'antd';
import type { InputRef } from 'antd';
import dayjs, { type Dayjs } from 'dayjs';
import { useEffect, useMemo, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { useAuthStore } from '@/features/auth/store';
import { listAllWarehouses } from '@/features/inventory/api';
import {
    findMaterialBagByBarcode,
    getFactoryDayBin,
    getFactoryDayBinReconciliation,
    listRawMaterials,
    loadBagToFactoryDayBin,
    factoryStoreLabel,
    loadFactoryDayBin,
    resolveFactoryStore,
    setDayBinWarehouse,
} from '@/features/production/api';
import { useProductionSettings } from '@/features/production/packing';
import type {
    FactoryDayBinLoadRow,
    FactoryDayBinReconciliationRow,
    FactoryDayBinUnopenedBags,
    MaterialBag,
} from '@/features/production/types';
import type { Item } from '@/features/inventory/types';
import { itemLabel } from '@/lib/itemLabel';

/** "1250.5000" → "1250.5"; "—" for null/unparseable. */
function fmtQty(value: string | null | undefined): string {
    if (value === null || value === undefined || value === '') return '—';
    const parsed = parseFloat(value);
    return Number.isNaN(parsed) ? '—' : String(parseFloat(parsed.toFixed(4)));
}

/**
 * How far a physical count may sit from the expected figure and still be
 * called a match: 50 grams. A bin is counted with a floor scale and a tape on
 * part-bags, so demanding exact equality would paint every honest count red
 * and teach the accountant to ignore the colour. Stated on screen in as many
 * words, because a tolerance nobody knows about is just a lie with a
 * threshold.
 */
const COUNT_MATCH_TOLERANCE_KG = 0.05;

/** The SKU line under a material name — or null when it just repeats the name. */
function skuLine(item: { sku: string; name: string }): string | null {
    const bare = (v: string) => v.toLowerCase().replace(/\s+/g, '');
    return item.sku.trim() === '' || bare(item.sku) === bare(item.name) ? null : item.sku;
}

/**
 * One row of the balances table — the union of the day-bin read's `summary`
 * and `materials` arrays, keyed by item_id.
 *
 * `store_kg`/`unopened_bags` are null for a material the `summary` array does
 * not cover, and null means UNKNOWN, never zero: an unknown store balance
 * shown as 0 reads as "the store is out of it", which would stop a shift for
 * no reason.
 */
interface BalanceRow {
    item_id: number;
    item?: Item;
    bin_kg: string;
    store_kg: string | null;
    unopened_bags: FactoryDayBinUnopenedBags | null;
}

/**
 * THE FACTORY DAY BIN — the owner's material control room, and the floor's
 * one scan point for material coming out of the store.
 *
 * Top to bottom, in the order the floor uses it:
 *
 *  1. SCAN — a barcode input built for a USB scanner gun: the gun types the
 *     code and presses Enter (lookup), the bag shows itself (material, SKU,
 *     kg remaining), and Enter again loads the whole bag — kg editable first
 *     for a part bag. Focus returns to the input after every load so bags
 *     scan one after another without touching the mouse. Same lookup + same
 *     POST /production/day-bin/load-bag the Shift Floor's Load Material
 *     modal uses — one flow, two doors.
 *  2. BALANCES — one row per raw material: in the day bin now, still in the
 *     store, bags still holding material. The bin figures are that
 *     warehouse's ordinary stock balances (the day bin IS a warehouse — no
 *     second arithmetic). The row set is the UNION of the read's two arrays:
 *     `summary` (kg-uom materials in the bin or the store, with all three
 *     figures) plus any `materials` row it doesn't cover — a non-kg material
 *     sitting in the bin still has to be visible, with "—" where the store
 *     and bag figures are genuinely unknown rather than zero.
 *  3. MANUAL LOAD (collapsed) — for unlabelled bags and opening stock: raw
 *     materials only (the backend's kg-uom picker — never the bottle list),
 *     posting the EXISTING store → bin stock transfer. A location move,
 *     never a consumption, never a Tally post.
 *  4. LOADED TODAY — every load into the bin today with time, material, kg,
 *     bag and who. Always shown once a bin is named: an empty list is the
 *     normal morning state and says so in one plain line.
 *
 * Consumption never happens here: Complete Batch on the Shift Floor issues
 * each material line FROM this warehouse, so the balances fall by themselves.
 *
 * Until someone names the day-bin warehouse, the page shows one plain line
 * asking for it and nothing else in the ERP changes behaviour.
 */
export default function FactoryDayBinPage() {
    const queryClient = useQueryClient();
    const [manualForm] = Form.useForm();
    const currentUser = useAuthStore((s) => s.user);
    const [pickingWarehouse, setPickingWarehouse] = useState<number | null>(null);
    const [manualOpenKeys, setManualOpenKeys] = useState<string[]>([]);
    const manualOpen = manualOpenKeys.includes('manual');

    // ----- Scan state: plain state, not a form — the driver is a barcode gun.
    const scanInputRef = useRef<InputRef>(null);
    const [scanCode, setScanCode] = useState('');
    const [scannedBag, setScannedBag] = useState<MaterialBag | null>(null);
    const [scanKg, setScanKg] = useState<number | null>(null);
    const [scanError, setScanError] = useState<string | null>(null);
    const [scanSuccess, setScanSuccess] = useState<string | null>(null);

    const { data: dayBin, isLoading } = useQuery({
        queryKey: ['production', 'factory-day-bin'],
        queryFn: getFactoryDayBin,
    });
    // Scanning resolves barcodes to bags, which only exist with the
    // traceability flag on (the routes 404 with it off) — same master switch
    // the Shift Floor's Load Material button obeys.
    const settings = useProductionSettings();
    const traceabilityEnabled = settings?.traceability_enabled === true;
    // The warehouse/material pick-lists are Inventory's reads. A production-only
    // login 403s on them — a normal answer, not a crash: the balances still
    // show and one plain line explains why the pickers are empty.
    const { data: warehouses, isError: warehousesUnavailable } = useQuery({
        queryKey: ['inventory', 'warehouses', 'all'],
        queryFn: listAllWarehouses,
        retry: false,
    });
    const { data: rawMaterials, isError: rawMaterialsUnavailable } = useQuery({
        queryKey: ['production', 'raw-materials'],
        queryFn: listRawMaterials,
        retry: false,
    });

    const dayBinWarehouse = dayBin?.warehouse ?? null;
    const configured = dayBinWarehouse !== null;

    // ----- The day's reconciliation: the CENTRAL "is any material missing?"
    // check. Defaults to today; yesterday is one click back, because
    // "what did we end last night with?" is the accountant's morning question
    // and the answer must not require a report run.
    const [reconDate, setReconDate] = useState<Dayjs>(() => dayjs());
    const reconDateKey = reconDate.format('YYYY-MM-DD');
    /**
     * The physical counts, keyed by item_id. LOCAL STATE ONLY — never sent,
     * never stored. A count is how the accountant interrogates the ERP's
     * figure, not a stock record: booking it would silently overwrite a
     * balance the movements are the authority on.
     *
     * Cleared whenever the date changes. Left standing, yesterday's typed
     * count would sit against today's expected closing and report a
     * discrepancy that never happened.
     */
    const [counts, setCounts] = useState<Record<number, number | null>>({});

    const {
        data: reconciliation,
        isLoading: reconLoading,
        isError: reconUnavailable,
    } = useQuery({
        // The date is part of the key: without it every date would serve the
        // first one fetched, from cache, under a picker that says otherwise.
        queryKey: ['production', 'factory-day-bin', 'reconciliation', reconDateKey],
        queryFn: () => getFactoryDayBinReconciliation(reconDateKey),
        enabled: configured,
        retry: false,
    });

    const warehouseOptions = useMemo(
        () =>
            (warehouses?.data ?? [])
                .filter((w) => w.is_active)
                .map((w) => ({ value: w.id, label: `${w.code} — ${w.name}` })),
        [warehouses],
    );
    // `sourceOptions` (warehouses minus the bin itself) is gone with the manual
    // load's "From" picker. `warehouseOptions` above survives for ONE purpose
    // only: the two controls that CONFIGURE which warehouse is the day bin.
    // That is a setup question with a real choice behind it, asked once by
    // whoever sets the factory up — not a question put to a supervisor
    // mid-shift about stock they are holding. It is also the target every
    // "could not work this out" link on the floor points at, so removing it
    // would leave those messages with nowhere to send anyone.
    // Raw materials ONLY — resin and masterbatch, everything bought by the
    // kg. Deliberately never the full items master: a day bin holding
    // "1 Litre Pet Bottle" is a booking mistake this Select refuses to allow.
    //
    // The picker route already sends the display string as `label`; NOT
    // itemLabel(), which reads `sku`/`name` this shape does not carry and
    // would render every option blank without failing a type-check.
    const rawMaterialOptions = useMemo(
        () => (rawMaterials ?? []).map((option) => ({ value: option.id, label: option.label })),
        [rawMaterials],
    );

    // The balances table's row set: `summary` first (the backend name-orders
    // it) and then any material in the bin it left out — never fewer rows
    // than either array alone.
    const balanceRows = useMemo<BalanceRow[]>(() => {
        const rows: BalanceRow[] = (dayBin?.summary ?? []).map((row) => ({
            item_id: row.item_id,
            item: row.item,
            bin_kg: row.bin_kg,
            store_kg: row.store_kg,
            unopened_bags: row.unopened_bags,
        }));

        const covered = new Set(rows.map((row) => row.item_id));
        for (const material of dayBin?.materials ?? []) {
            if (covered.has(material.item_id)) continue;
            covered.add(material.item_id);
            rows.push({
                item_id: material.item_id,
                item: material.item,
                bin_kg: material.quantity_kg,
                store_kg: null,
                unopened_bags: null,
            });
        }

        return rows;
    }, [dayBin]);

    /**
     * WHICH STORE THE MATERIAL CAME OUT OF — resolved, never asked.
     *
     * This is the one warehouse question on the floor that the SERVER cannot
     * answer for us: a manual day-bin load is a plain stock transfer, and its
     * endpoint still requires a real `from_warehouse_id`. So the id is worked
     * out here instead — but by the books, not by warehouse names.
     *
     * What this replaced was a name heuristic (/raw|rm|resin/ scored against
     * code and name, excluding /fg|finish|dispatch/), and before that a rule
     * preferring any name containing "store" — which on this factory's masters
     * is FG Store, so the form once offered to book PET resin out of the
     * bottle warehouse. Warehouse names are not a fact. `tally_guid` is: it is
     * written only by WarehouseService::syncGodownsFromTally, mirroring the
     * accountant's godown list, and this factory's Tally carries exactly one
     * godown. So "the single active Tally-linked warehouse" IS the factory,
     * and rehearsal residue (RM-STORE, WIP, FG-STORE) can never be it.
     *
     * The bin is excluded because the transfer endpoint refuses from === to.
     * undefined means the books do not name a single store, and then the panel
     * says so plainly and refuses to submit rather than guessing.
     */
    const factoryStore = useMemo(
        () => resolveFactoryStore(warehouses?.data, dayBinWarehouse?.id ?? null),
        [warehouses, dayBinWarehouse],
    );

    const chooseWarehouse = useMutation({
        mutationFn: (warehouseId: number | null) => setDayBinWarehouse(warehouseId),
        onSuccess: () => {
            message.success('Day bin warehouse saved');
            setPickingWarehouse(null);
            queryClient.invalidateQueries({ queryKey: ['production', 'factory-day-bin'] });
            // The Shift Floor reads the setting from /production/settings to
            // default its consumption warehouse — it must see this at once.
            queryClient.invalidateQueries({ queryKey: ['production', 'settings'] });
        },
        onError: (error: any) => {
            message.error(
                error?.response?.status === 403
                    ? 'You do not have permission to change production settings (needs Production: Manage).'
                    : (error?.response?.data?.message ?? 'Could not save the day bin warehouse'),
            );
        },
    });

    // ----- Scan flow: lookup on Enter, load on the next Enter (or the button).

    const bagLookup = useMutation({
        mutationFn: findMaterialBagByBarcode,
        onSuccess: (bag, barcode) => {
            if (!bag) {
                setScannedBag(null);
                setScanKg(null);
                setScanError(`No open bag with barcode "${barcode}" in the store.`);
                return;
            }
            setScannedBag(bag);
            // Prefill the whole bag; the field stays editable for a part bag.
            setScanKg(Number(bag.remaining_kg));
            setScanError(null);
            // Back to the gun: Enter now loads, or the next scan replaces.
            scanInputRef.current?.focus();
        },
        onError: (error: any) => {
            setScannedBag(null);
            setScanKg(null);
            setScanError(error?.response?.data?.message ?? 'Could not look up that barcode.');
        },
    });

    const bagLoad = useMutation({
        mutationFn: loadBagToFactoryDayBin,
        onSuccess: (result, payload) => {
            // Compose the confirmation from the response where it answers,
            // falling back to what was scanned — never a blank.
            const material = result?.day_bin?.item ?? result?.bag?.lot?.item ?? scannedBag?.lot?.item ?? null;
            const balance = result?.day_bin?.quantity_kg;
            setScanSuccess(
                `Loaded ${payload.quantity_kg} kg of ${material ? itemLabel(material) : 'material'}` +
                    `${balance ? ` — day bin now holds ${balance} kg` : ''}.`,
            );
            setScannedBag(null);
            setScanKg(null);
            setScanError(null);
            // The bag lost kg and the bin gained it — every surface quoting
            // either must move.
            queryClient.invalidateQueries({ queryKey: ['production', 'factory-day-bin'] });
            queryClient.invalidateQueries({ queryKey: ['inventory', 'stock-balances'] });
            queryClient.invalidateQueries({ queryKey: ['production', 'material-bags', 'pick-list'] });
            // Back to the gun: the next bag scans without a tap.
            scanInputRef.current?.focus();
        },
        onError: (error: any) => {
            setScanError(error?.response?.data?.message ?? 'Could not load the bag into the day bin.');
        },
    });

    const submitBagLoad = () => {
        if (!scannedBag || !scanKg || scanKg <= 0 || !currentUser || bagLoad.isPending) return;
        bagLoad.mutate({
            barcode: scannedBag.barcode,
            quantity_kg: scanKg,
            // Recorded as a note; the audit identity is the login either way.
            supervisor_id: currentUser.id,
        });
    };

    /**
     * One Enter key, two meanings: a typed/scanned code looks the bag up; an
     * EMPTY Enter with a bag on screen loads it — so gun-scan → Enter loads
     * the whole bag with no other touch.
     */
    const submitScan = () => {
        const code = scanCode.trim();
        if (code !== '') {
            setScanCode('');
            setScanSuccess(null);
            bagLookup.mutate(code);
            return;
        }
        if (scannedBag) submitBagLoad();
    };

    // ----- Manual (no-barcode) load: the existing store → bin transfer.

    const manualLoad = useMutation({
        // No `from_warehouse_id` in the form values any more — the resolved
        // factory store supplies it. The submit is disabled unless it resolved,
        // so the non-null assertion here is guarded by the button, the same way
        // `dayBinWarehouse!` already is.
        mutationFn: (values: { item_id: number; quantity: number; loaded_at?: Dayjs }) =>
            loadFactoryDayBin({
                item_id: values.item_id,
                from_warehouse_id: factoryStore!.id,
                to_warehouse_id: dayBinWarehouse!.id,
                quantity: values.quantity,
                movement_date: (values.loaded_at ?? dayjs()).format('YYYY-MM-DD HH:mm:ss'),
                reference: 'Day bin load',
            }),
        onSuccess: () => {
            message.success('Loaded into the day bin');
            // Keep the source and time — unlabelled stock usually arrives as
            // several materials from the same store in one go.
            manualForm.setFieldsValue({ item_id: undefined, quantity: undefined });
            queryClient.invalidateQueries({ queryKey: ['production', 'factory-day-bin'] });
            queryClient.invalidateQueries({ queryKey: ['inventory', 'stock-balances'] });
        },
        onError: (error: any) => {
            const status = error?.response?.status;
            if (status === 403) {
                message.error('You do not have permission to move stock (needs Inventory: Manage).');
                return;
            }
            message.error(error?.response?.data?.message ?? 'Could not load the day bin');
        },
    });

    // ----- Tables ----------------------------------------------------------

    const balanceColumns = [
        {
            title: 'Material',
            key: 'material',
            render: (_: unknown, row: BalanceRow) =>
                row.item ? (
                    <Space direction="vertical" size={0}>
                        <Typography.Text strong>{row.item.name}</Typography.Text>
                        {skuLine(row.item) !== null && (
                            <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                                {skuLine(row.item)}
                            </Typography.Text>
                        )}
                    </Space>
                ) : (
                    <Typography.Text type="secondary">Item #{row.item_id}</Typography.Text>
                ),
        },
        {
            title: 'In day bin',
            key: 'in_bin',
            align: 'right' as const,
            render: (_: unknown, row: BalanceRow) => (
                <Typography.Text strong style={{ fontSize: 18 }}>
                    {fmtQty(row.bin_kg)} <Typography.Text type="secondary">{row.item?.uom ?? 'Kg'}</Typography.Text>
                </Typography.Text>
            ),
        },
        {
            title: 'In store',
            key: 'in_store',
            align: 'right' as const,
            // "—" (not 0) for a material the summary doesn't cover — an
            // unknown store balance must never read as an empty store.
            render: (_: unknown, row: BalanceRow) =>
                row.store_kg === null ? (
                    <Typography.Text type="secondary">—</Typography.Text>
                ) : (
                    <Typography.Text>
                        {fmtQty(row.store_kg)} <Typography.Text type="secondary">{row.item?.uom ?? 'Kg'}</Typography.Text>
                    </Typography.Text>
                ),
        },
        {
            title: 'Unopened bags',
            key: 'unopened',
            align: 'right' as const,
            render: (_: unknown, row: BalanceRow) =>
                row.unopened_bags === null ? (
                    <Typography.Text type="secondary">—</Typography.Text>
                ) : (
                    <Typography.Text>
                        {row.unopened_bags.count} <Typography.Text type="secondary">·</Typography.Text>{' '}
                        {fmtQty(row.unopened_bags.kg)} <Typography.Text type="secondary">kg</Typography.Text>
                    </Typography.Text>
                ),
        },
    ];

    /**
     * The difference between a typed physical count and what the bin SHOULD
     * hold. This is the only real check on the page — every other figure in the
     * row is derived from the movement ledger and so cannot disagree with it.
     *
     * COMPARED AGAINST expected_closing + other_movements, never expected
     * closing alone. The server defines expected closing strictly as
     * opening + loaded − consumed; material that moved by neither route — a
     * receipt booked straight into the bin, a transfer back out to the store —
     * lands in `other_movements_kg` instead, and the bin's live balance is
     * their SUM. Subtracting a count from the bare expected figure would invent
     * a shortfall on exactly the days somebody had already accounted for one,
     * which is the false alarm this whole change exists to stop.
     */
    const countVerdict = (row: FactoryDayBinReconciliationRow, count: number | null) => {
        if (count === null || Number.isNaN(count)) return null;
        const expected = parseFloat(row.expected_closing_kg) + parseFloat(row.other_movements_kg);
        if (Number.isNaN(expected)) return null;

        const diff = count - expected;
        if (Math.abs(diff) <= COUNT_MATCH_TOLERANCE_KG) {
            return <Typography.Text type="success">matches</Typography.Text>;
        }
        // The sign is spelled with the character, not left to a formatter, and
        // the word beside it says which way the gap runs — "−4.8" alone has
        // been read as "4.8 spare" often enough to be worth six characters.
        return diff < 0 ? (
            <Typography.Text type="danger" strong>
                −{fmtQty(Math.abs(diff).toFixed(4))} kg missing
            </Typography.Text>
        ) : (
            <Typography.Text type="warning" strong>
                +{fmtQty(diff.toFixed(4))} kg extra
            </Typography.Text>
        );
    };

    const reconColumns = [
        {
            title: 'Material',
            key: 'material',
            render: (_: unknown, row: FactoryDayBinReconciliationRow) => (
                <Space direction="vertical" size={0}>
                    <Typography.Text strong>{row.item.name}</Typography.Text>
                    {skuLine(row.item) !== null && (
                        <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                            {skuLine(row.item)}
                        </Typography.Text>
                    )}
                </Space>
            ),
        },
        {
            title: 'Opening',
            key: 'opening',
            align: 'right' as const,
            render: (_: unknown, row: FactoryDayBinReconciliationRow) => fmtQty(row.opening_kg),
        },
        {
            title: 'Loaded',
            key: 'loaded',
            align: 'right' as const,
            render: (_: unknown, row: FactoryDayBinReconciliationRow) => fmtQty(row.loaded_kg),
        },
        {
            title: 'Consumed',
            key: 'consumed',
            align: 'right' as const,
            render: (_: unknown, row: FactoryDayBinReconciliationRow) => fmtQty(row.consumed_kg),
        },
        {
            title: 'Expected closing',
            key: 'expected',
            align: 'right' as const,
            render: (_: unknown, row: FactoryDayBinReconciliationRow) => {
                const other = parseFloat(row.other_movements_kg);
                const hasOther = !Number.isNaN(other) && other !== 0;
                return (
                    <Space direction="vertical" size={0} align="end">
                        <Typography.Text strong style={{ fontSize: 16 }}>
                            {fmtQty(row.expected_closing_kg)}{' '}
                            <Typography.Text type="secondary">{row.item.uom}</Typography.Text>
                        </Typography.Text>
                        {/* Normally absent. When it is not, the count is being
                            compared against expected + this — so the row names
                            BOTH the movement and the figure a count is actually
                            measured against, rather than reporting a gap the
                            screen cannot explain. */}
                        {hasOther && (
                            <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                                {other > 0 ? '+' : '−'}
                                {fmtQty(Math.abs(other).toFixed(4))} kg by another route (booked into the bin, or
                                returned to the store) — a count is compared against{' '}
                                {fmtQty((parseFloat(row.expected_closing_kg) + other).toFixed(4))} kg
                            </Typography.Text>
                        )}
                    </Space>
                );
            },
        },
        {
            title: 'Physical count (kg)',
            key: 'count',
            align: 'right' as const,
            render: (_: unknown, row: FactoryDayBinReconciliationRow) => (
                <InputNumber
                    min={0}
                    step={0.1}
                    style={{ width: 140 }}
                    placeholder="weigh the bin"
                    value={counts[row.item_id] ?? null}
                    onChange={(value) => setCounts((prev) => ({ ...prev, [row.item_id]: value }))}
                />
            ),
        },
        {
            title: 'Difference',
            key: 'difference',
            render: (_: unknown, row: FactoryDayBinReconciliationRow) =>
                countVerdict(row, counts[row.item_id] ?? null) ?? (
                    <Typography.Text type="secondary">—</Typography.Text>
                ),
        },
    ];

    const loadColumns = [
        {
            title: 'Time',
            key: 'time',
            // Guarded: dayjs(null) silently means "now", which would print a
            // believable but invented clock time.
            render: (_: unknown, row: FactoryDayBinLoadRow) =>
                row.time === null ? <Typography.Text type="secondary">—</Typography.Text> : dayjs(row.time).format('HH:mm'),
        },
        {
            title: 'Material',
            key: 'material',
            render: (_: unknown, row: FactoryDayBinLoadRow) =>
                row.item ? itemLabel(row.item) : <Typography.Text type="secondary">—</Typography.Text>,
        },
        {
            title: 'Kg',
            key: 'kg',
            align: 'right' as const,
            render: (_: unknown, row: FactoryDayBinLoadRow) => fmtQty(row.quantity_kg),
        },
        {
            title: 'Bag',
            key: 'bag',
            render: (_: unknown, row: FactoryDayBinLoadRow) =>
                row.bag_barcode ?? <Typography.Text type="secondary">no barcode</Typography.Text>,
        },
        {
            title: 'Who',
            key: 'who',
            render: (_: unknown, row: FactoryDayBinLoadRow) =>
                row.user ?? <Typography.Text type="secondary">—</Typography.Text>,
        },
    ];

    return (
        <Space direction="vertical" size="large" style={{ width: '100%' }}>
            <Space direction="vertical" size={0}>
                <Typography.Title level={3} style={{ marginBottom: 0 }}>
                    Day Bin (factory)
                </Typography.Title>
                <Typography.Text type="secondary">
                    Material control room: scan bags in at the top, see what the factory can run right now, and check
                    every load made today. Machines empty the bin by themselves at Complete Batch — nothing is consumed
                    here, and loading posts nothing to Tally.
                </Typography.Text>
            </Space>

            {warehousesUnavailable && (
                <Alert
                    type="warning"
                    showIcon
                    message="You can see the day bin but not set it up or load it by hand"
                    description="Choosing a warehouse and moving material both need Inventory access, which this login does not have. Ask for Inventory access (View to pick, Manage to load)."
                />
            )}

            {!configured && (
                <Alert
                    type="info"
                    showIcon
                    message="No day bin warehouse chosen yet"
                    description={
                        <Space direction="vertical" style={{ width: '100%' }}>
                            <Typography.Text>
                                Pick which warehouse is the factory day bin. Until then everything works exactly as it
                                does today.
                            </Typography.Text>
                            <Space wrap>
                                <Select
                                    style={{ minWidth: 280 }}
                                    placeholder="Choose the day bin warehouse…"
                                    options={warehouseOptions}
                                    showSearch
                                    optionFilterProp="label"
                                    value={pickingWarehouse ?? undefined}
                                    onChange={(value) => setPickingWarehouse(value)}
                                />
                                <Button
                                    type="primary"
                                    disabled={pickingWarehouse === null}
                                    loading={chooseWarehouse.isPending}
                                    onClick={() => chooseWarehouse.mutate(pickingWarehouse)}
                                >
                                    Save
                                </Button>
                            </Space>
                        </Space>
                    }
                />
            )}

            {configured && (
                <>
                    {traceabilityEnabled && (
                        <Card size="small" title="Scan a bag in">
                            <Typography.Paragraph type="secondary" style={{ marginBottom: 12 }}>
                                The scanner types the code and presses Enter by itself — the bag shows below. Enter
                                again (or the button) loads the whole bag; lower the kg first for a part bag.
                            </Typography.Paragraph>
                            {scanSuccess && (
                                <Alert type="success" showIcon message={scanSuccess} style={{ marginBottom: 12 }} />
                            )}
                            {scanError && (
                                <Alert type="error" showIcon message={scanError} style={{ marginBottom: 12 }} />
                            )}
                            <Input
                                ref={scanInputRef}
                                autoFocus
                                size="large"
                                value={scanCode}
                                onChange={(e) => setScanCode(e.target.value)}
                                onPressEnter={submitScan}
                                placeholder="Scan or type the bag barcode, then Enter"
                                style={{ maxWidth: 480 }}
                            />
                            {bagLookup.isPending && (
                                <Typography.Paragraph type="secondary" style={{ marginTop: 8, marginBottom: 0 }}>
                                    Looking up the bag…
                                </Typography.Paragraph>
                            )}
                            {scannedBag && (
                                <div style={{ marginTop: 12, maxWidth: 480 }}>
                                    <Descriptions size="small" column={1} bordered style={{ marginBottom: 12 }}>
                                        <Descriptions.Item label="Bag">{scannedBag.barcode}</Descriptions.Item>
                                        <Descriptions.Item label="Material">
                                            {scannedBag.lot?.item ? itemLabel(scannedBag.lot.item) : '—'}
                                        </Descriptions.Item>
                                        <Descriptions.Item label="Remaining in bag (kg)">
                                            {fmtQty(scannedBag.remaining_kg)}
                                        </Descriptions.Item>
                                    </Descriptions>
                                    <Form layout="vertical" component="div">
                                        <Form.Item
                                            label="Kg to load"
                                            extra="The whole bag unless you lower it for a part bag."
                                        >
                                            <InputNumber
                                                min={0.001}
                                                max={Number(scannedBag.remaining_kg)}
                                                value={scanKg}
                                                onChange={(value) => setScanKg(value)}
                                                style={{ width: '100%' }}
                                            />
                                        </Form.Item>
                                    </Form>
                                    <Button
                                        type="primary"
                                        block
                                        onClick={submitBagLoad}
                                        loading={bagLoad.isPending}
                                        disabled={!scanKg || scanKg <= 0}
                                    >
                                        Load into Day Bin
                                    </Button>
                                </div>
                            )}
                        </Card>
                    )}

                    <Card
                        size="small"
                        title={
                            <Space wrap>
                                <span>
                                    Day bin: {dayBinWarehouse.code} — {dayBinWarehouse.name}
                                </span>
                                {dayBinWarehouse.tally_guid === null && (
                                    <Tag>Internal bin — Tally keeps seeing the main godown</Tag>
                                )}
                            </Space>
                        }
                        extra={
                            <Space wrap>
                                <Select
                                    size="small"
                                    style={{ minWidth: 220 }}
                                    placeholder="Change warehouse…"
                                    options={warehouseOptions}
                                    showSearch
                                    optionFilterProp="label"
                                    value={pickingWarehouse ?? undefined}
                                    onChange={(value) => setPickingWarehouse(value)}
                                />
                                <Button
                                    size="small"
                                    disabled={pickingWarehouse === null || pickingWarehouse === dayBinWarehouse.id}
                                    loading={chooseWarehouse.isPending}
                                    onClick={() => chooseWarehouse.mutate(pickingWarehouse)}
                                >
                                    Change
                                </Button>
                            </Space>
                        }
                    >
                        <Table<BalanceRow>
                            rowKey={(row) => row.item_id}
                            size="middle"
                            loading={isLoading}
                            columns={balanceColumns}
                            dataSource={balanceRows}
                            pagination={false}
                            scroll={{ x: 'max-content' }}
                            // A bin nobody has loaded yet is a normal state:
                            // one plain line, never a table full of blanks.
                            locale={{
                                emptyText: (
                                    <Typography.Text type="secondary">
                                        {traceabilityEnabled
                                            ? 'Nothing in the day bin yet — scan a bag in above.'
                                            : 'Nothing in the day bin yet — load material below.'}
                                    </Typography.Text>
                                ),
                            }}
                        />
                    </Card>

                    {/* THE DAY'S RECONCILIATION — the central replacement for
                        the per-batch "unaccounted kg" figure the owner had
                        removed. Directly under the balances, because it is the
                        same materials asked a harder question. */}
                    <Card
                        size="small"
                        title="Is anything missing? — the day's check"
                        extra={
                            <Space wrap>
                                <Button
                                    size="small"
                                    onClick={() => {
                                        setReconDate(dayjs().subtract(1, 'day'));
                                        setCounts({});
                                    }}
                                >
                                    Yesterday
                                </Button>
                                <DatePicker
                                    size="small"
                                    allowClear={false}
                                    value={reconDate}
                                    format="DD MMM YYYY"
                                    // A future date would be answered with
                                    // balances rolled forward past every
                                    // movement that has not happened yet — a
                                    // confident figure about nothing.
                                    disabledDate={(current) => current.isAfter(dayjs(), 'day')}
                                    onChange={(value) => {
                                        if (!value) return;
                                        setReconDate(value);
                                        // Yesterday's count must not stand
                                        // against today's expected closing.
                                        setCounts({});
                                    }}
                                />
                            </Space>
                        }
                    >
                        <Typography.Paragraph type="secondary" style={{ marginBottom: 12 }}>
                            What the bin started with, what went in, what the batches took out, and what should therefore
                            be left. Weigh the bin and type the count to see the gap. Resin consumption is calculated per
                            batch (production + rejection + lumps), so a single batch can never show a shortfall — this is
                            where missing material shows up.
                        </Typography.Paragraph>

                        {reconUnavailable ? (
                            <Typography.Text type="secondary">
                                The day's check could not be loaded. The balances above are unaffected.
                            </Typography.Text>
                        ) : (
                            <>
                                <Table<FactoryDayBinReconciliationRow>
                                    rowKey={(row) => row.item_id}
                                    size="middle"
                                    loading={reconLoading}
                                    columns={reconColumns}
                                    dataSource={reconciliation?.materials ?? []}
                                    pagination={false}
                                    scroll={{ x: 'max-content' }}
                                    // The backend omits any material the date
                                    // has nothing to say about, so an empty
                                    // table is a real answer about a quiet day.
                                    locale={{
                                        emptyText: (
                                            <Typography.Text type="secondary">
                                                Nothing moved through the bin on {reconDate.format('DD MMM YYYY')}, and it
                                                held nothing that morning.
                                            </Typography.Text>
                                        ),
                                    }}
                                />
                                {/* The quiet line. A count is a question put to
                                    the ERP, not an entry in it — said plainly so
                                    nobody types one expecting the balance to
                                    move, and nobody hunts for where it was
                                    filed. */}
                                <Typography.Text type="secondary" style={{ display: 'block', marginTop: 12, fontSize: 12 }}>
                                    Counts are a check, not a record — nothing here is saved, and the bin's balance is
                                    unchanged by what you type. Within {COUNT_MATCH_TOLERANCE_KG} kg reads as a match. The
                                    count must be the bin as it stood at the END of {reconDate.format('DD MMM YYYY')}: on a
                                    past date, weighing the bin now measures material loaded since, not that day's closing.
                                    To correct a real gap, book the stock movement that explains it.
                                </Typography.Text>
                            </>
                        )}
                    </Card>

                    <Collapse
                        activeKey={manualOpenKeys}
                        onChange={(keys) => setManualOpenKeys(keys as string[])}
                        items={[
                            {
                                key: 'manual',
                                label: 'Load without a barcode — unlabelled bags or opening stock',
                                children: (
                                    <Form
                                        form={manualForm}
                                        layout="vertical"
                                        onFinish={(values) => manualLoad.mutate(values)}
                                        initialValues={{ loaded_at: dayjs() }}
                                    >
                                        <Row gutter={[12, 0]}>
                                            <Col xs={24} md={8}>
                                                <Form.Item
                                                    name="item_id"
                                                    label="Material"
                                                    extra={
                                                        rawMaterialsUnavailable
                                                            ? 'Could not load the raw-material list — reload the page, or ask for Production access if this keeps happening.'
                                                            : undefined
                                                    }
                                                    rules={[{ required: true, message: 'Pick the material' }]}
                                                >
                                                    <Select
                                                        size="large"
                                                        options={rawMaterialOptions}
                                                        showSearch
                                                        optionFilterProp="label"
                                                        placeholder="Resin / Masterbatch / …"
                                                        notFoundContent={
                                                            <Empty
                                                                image={Empty.PRESENTED_IMAGE_SIMPLE}
                                                                description="No raw materials (kg items) found"
                                                            />
                                                        }
                                                    />
                                                </Form.Item>
                                            </Col>
                                            <Col xs={12} md={4}>
                                                <Form.Item
                                                    name="quantity"
                                                    label="Kg"
                                                    rules={[
                                                        { required: true, message: 'Enter the kg' },
                                                        // The transfer endpoint requires gt:0 — say so here
                                                        // rather than letting the floor read a 422.
                                                        {
                                                            validator: (_, value) =>
                                                                value === undefined || value === null || value > 0
                                                                    ? Promise.resolve()
                                                                    : Promise.reject(new Error('Must be more than zero')),
                                                        },
                                                    ]}
                                                >
                                                    <InputNumber
                                                        size="large"
                                                        min={0}
                                                        style={{ width: '100%' }}
                                                        placeholder="Kg"
                                                    />
                                                </Form.Item>
                                            </Col>
                                            <Col xs={12} md={6}>
                                                <Form.Item name="loaded_at" label="Date & time">
                                                    <DatePicker
                                                        size="large"
                                                        showTime
                                                        format="DD MMM YYYY HH:mm"
                                                        style={{ width: '100%' }}
                                                    />
                                                </Form.Item>
                                            </Col>
                                        </Row>
                                        {/* Where it came from: STATED in one
                                            line, not picked. One factory, one
                                            place — and when the books cannot
                                            name it, the panel says exactly that
                                            and refuses rather than moving stock
                                            out of a warehouse nobody chose. */}
                                        {factoryStore ? (
                                            <Typography.Text
                                                type="secondary"
                                                style={{ display: 'block', fontSize: 12, marginBottom: 8 }}
                                            >
                                                Comes out of {factoryStoreLabel(factoryStore)}.
                                            </Typography.Text>
                                        ) : (
                                            <Alert
                                                type="warning"
                                                showIcon
                                                style={{ marginBottom: 8 }}
                                                message="No store to load from"
                                                description={
                                                    <Typography.Text>
                                                        The factory store could not be worked out — there is no single
                                                        Tally-linked warehouse to move this material out of. Link the
                                                        factory godown on <Link to="/inventory/warehouses">Warehouses</Link>{' '}
                                                        and this panel works again.
                                                    </Typography.Text>
                                                }
                                            />
                                        )}
                                        <Button
                                            type="primary"
                                            size="large"
                                            htmlType="submit"
                                            disabled={!factoryStore}
                                            loading={manualLoad.isPending}
                                        >
                                            Load into Day Bin
                                        </Button>
                                    </Form>
                                ),
                            },
                        ]}
                    />

                    <Card size="small" title="Loaded today">
                        <Table<FactoryDayBinLoadRow>
                            rowKey={(row) => row.id}
                            size="small"
                            loading={isLoading}
                            columns={loadColumns}
                            dataSource={dayBin?.todays_loads ?? []}
                            pagination={false}
                            scroll={{ x: 'max-content' }}
                            locale={{
                                emptyText: (
                                    <Typography.Text type="secondary">
                                        Nothing loaded into the day bin yet today.
                                    </Typography.Text>
                                ),
                            }}
                        />
                    </Card>
                </>
            )}
        </Space>
    );
}
